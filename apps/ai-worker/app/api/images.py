from datetime import datetime

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from app.api.llm import GenerationMeta, generation_meta
from app.generators.writing_plan import FactInput
from app.llm.factory import get_router
from app.llm.router import LLMRouter
from app.llm.types import Target
from app.storage import UnsafePathError, resolve_upload_path, to_storage_key
from app.vision import analysis
from app.vision.processing import InvalidImageError, process_image

router = APIRouter(prefix="/images")


class ProcessImageRequest(BaseModel):
    source: str = Field(description="업로드 볼륨 기준 원본 상대 경로")
    dest_prefix: str = Field(description="결과 파일 경로(확장자 제외)")
    strip_exif: bool = True


class ProcessImageResponse(BaseModel):
    storage_key: str
    thumb_key: str
    mime_type: str
    width: int
    height: int
    size_bytes: int
    taken_at: datetime | None


@router.post("/process")
def process(request: ProcessImageRequest) -> ProcessImageResponse:
    try:
        source = resolve_upload_path(request.source)
        dest_prefix = resolve_upload_path(request.dest_prefix)
    except UnsafePathError as error:
        raise HTTPException(status_code=400, detail="업로드 경로 밖의 파일입니다.") from error

    if not source.is_file():
        raise HTTPException(status_code=404, detail="원본 파일이 없습니다.")

    try:
        result = process_image(source, dest_prefix, strip_exif=request.strip_exif)
    except InvalidImageError as error:
        raise HTTPException(status_code=422, detail="이미지로 읽을 수 없는 파일입니다.") from error

    return ProcessImageResponse(
        storage_key=to_storage_key(result.main_path),
        thumb_key=to_storage_key(result.thumb_path),
        mime_type="image/jpeg",
        width=result.width,
        height=result.height,
        size_bytes=result.size_bytes,
        taken_at=result.taken_at,
    )


class AnalyzeImagesRequest(BaseModel):
    keyword: str
    category: str | None = None
    facts: list[FactInput] = []
    images: list[analysis.PhotoRef]
    targets: list[str] | None = None


class AnalyzeImagesResponse(BaseModel):
    results: list[analysis.VisionResult]
    failed_ids: list[int]
    error: str | None
    prompt_version: str
    generations: list[GenerationMeta]


@router.post("/analyze")
async def analyze(body: AnalyzeImagesRequest, llm: Annotated[LLMRouter, Depends(get_router)]) -> AnalyzeImagesResponse:
    if not body.images:
        raise HTTPException(status_code=422, detail="분석할 사진이 없습니다.")
    try:
        route = [Target.parse(spec) for spec in body.targets] if body.targets else None
        for photo in body.images:
            if not resolve_upload_path(photo.storage_key).is_file():
                raise HTTPException(status_code=422, detail=f"사진 파일이 없습니다: {photo.id}")
    except (ValueError, UnsafePathError) as error:
        raise HTTPException(status_code=400, detail=str(error)) from error

    results, failed_ids, outcomes, errors = await analysis.analyze_photos(
        llm, body.images, " ".join(body.keyword.split()), body.category, body.facts, route
    )
    return AnalyzeImagesResponse(
        results=results,
        failed_ids=failed_ids,
        error=("사진을 분석하지 못했습니다: " + ", ".join(errors)) if errors else None,
        prompt_version=analysis.PROMPT_VERSION,
        generations=[meta for outcome in outcomes for meta in generation_meta(outcome)],
    )
