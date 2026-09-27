from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel

from app.analyzers.aggregate import KeywordStats, aggregate
from app.analyzers.features import DocumentFeatures
from app.api.llm import GenerationMeta, generation_meta
from app.generators.keyword_insight import PROMPT_VERSION, KeywordInsight, generate_insight
from app.llm.factory import get_router
from app.llm.router import AllTargetsFailed, LLMRouter
from app.llm.types import Target

router = APIRouter(prefix="/keywords")


class AnalyzeRequest(BaseModel):
    keyword: str
    category: str | None = None
    features: list[DocumentFeatures]
    targets: list[str] | None = None


class AnalyzeResponse(BaseModel):
    stats: KeywordStats | None
    # 모든 LLM 대상이 실패하면 null. 통계는 그래도 돌려준다.
    insight: KeywordInsight | None
    insight_error: str | None
    prompt_version: str
    generations: list[GenerationMeta]


@router.post("/analyze")
async def analyze(body: AnalyzeRequest, llm: Annotated[LLMRouter, Depends(get_router)]) -> AnalyzeResponse:
    try:
        route = [Target.parse(spec) for spec in body.targets] if body.targets else None
    except ValueError as error:
        raise HTTPException(status_code=422, detail=str(error)) from error

    keyword = " ".join(body.keyword.split())
    stats = aggregate(body.features, keyword)

    try:
        outcome = await generate_insight(llm, keyword, body.category, stats, route)
    except AllTargetsFailed as failed:
        kinds = sorted({a.error.kind for a in failed.outcome.attempts if a.error})
        return AnalyzeResponse(
            stats=stats,
            insight=None,
            insight_error="AI 해석을 만들지 못했습니다: " + ", ".join(kinds),
            prompt_version=PROMPT_VERSION,
            generations=generation_meta(failed.outcome),
        )

    return AnalyzeResponse(
        stats=stats,
        insight=outcome.result.parsed,
        insight_error=None,
        prompt_version=PROMPT_VERSION,
        generations=generation_meta(outcome),
    )
