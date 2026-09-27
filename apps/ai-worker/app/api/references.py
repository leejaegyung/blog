from functools import lru_cache
from typing import Annotated, Literal

import httpx2
from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel

from app.analyzers.features import DocumentFeatures, extract_features
from app.references.extract import EmptyContentError, from_html, from_text
from app.references.fetcher import FetchError, PageFetcher
from app.storage import UnsafePathError, resolve_upload_path

router = APIRouter(prefix="/references")


@lru_cache
def get_fetcher() -> PageFetcher:
    return PageFetcher(httpx2.AsyncClient(timeout=httpx2.Timeout(15.0, connect=5.0)))


class ParseRequest(BaseModel):
    source: Literal["url", "text"]
    keyword: str
    url: str | None = None
    text_key: str | None = None
    title: str | None = None


class ParseResponse(BaseModel):
    """본문은 돌려주지도 저장하지도 않는다(2026-09-27 결정: 특징만 남긴다)."""

    final_url: str | None
    title: str | None
    author: str | None
    published_at: str | None
    content_hash: str
    extractor: str
    features: DocumentFeatures


def _error(status: int, code: str, message: str) -> HTTPException:
    return HTTPException(status_code=status, detail={"code": code, "message": message})


@router.post("/parse")
async def parse(body: ParseRequest, fetcher: Annotated[PageFetcher, Depends(get_fetcher)]) -> ParseResponse:
    try:
        text_path = resolve_upload_path(body.text_key) if body.text_key else None
    except UnsafePathError as error:
        raise _error(400, "unsafe_path", "업로드 경로 밖의 파일입니다.") from error

    try:
        if body.source == "url":
            if not body.url:
                raise _error(422, "invalid_url", "URL이 없습니다.")
            try:
                page = await fetcher.fetch(body.url)
            except FetchError as error:
                raise _error(502 if error.transient else 422, error.code, error.message) from error
            document = from_html(page.content, page.url)
        else:
            if text_path is None or not text_path.is_file():
                raise _error(422, "missing_text", "붙여넣은 본문이 없습니다. 다시 붙여넣어 주세요.")
            document = from_text(text_path.read_text(encoding="utf-8"), title=body.title)
    except EmptyContentError as error:
        raise _error(422, "empty_content", str(error)) from error

    if body.title and not document.title:
        document = document.model_copy(update={"title": body.title})

    return ParseResponse(
        final_url=document.url,
        title=document.title,
        author=document.author,
        published_at=document.published_at,
        content_hash=document.content_hash,
        extractor=document.extractor,
        features=extract_features(document, body.keyword),
    )
