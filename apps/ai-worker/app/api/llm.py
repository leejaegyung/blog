"""LLM 호출 공통 응답 형식과 연결 확인용 엔드포인트.

글 생성 파이프라인(Day 7~9)도 같은 `GenerationMeta` 목록을 돌려주고, Laravel이 이를 `generations`에 기록한다.
"""

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from app.llm.factory import get_router
from app.llm.router import AllTargetsFailed, LLMRouter, RouteOutcome
from app.llm.types import LLMRequest, Target

router = APIRouter(prefix="/llm")


class GenerationMeta(BaseModel):
    provider: str
    model: str
    status: str
    input_tokens: int = 0
    output_tokens: int = 0
    latency_ms: int
    error_kind: str | None = None
    error_message: str | None = None


def generation_meta(outcome: RouteOutcome) -> list[GenerationMeta]:
    metas = []
    for attempt in outcome.attempts:
        if attempt.result:
            metas.append(
                GenerationMeta(
                    provider=attempt.result.provider,
                    model=attempt.result.model,
                    status="success",
                    input_tokens=attempt.result.input_tokens,
                    output_tokens=attempt.result.output_tokens,
                    latency_ms=attempt.latency_ms,
                )
            )
        else:
            metas.append(
                GenerationMeta(
                    provider=attempt.target.provider,
                    model=attempt.target.model,
                    status="failed",
                    latency_ms=attempt.latency_ms,
                    error_kind=attempt.error.kind if attempt.error else None,
                    error_message=str(attempt.error)[:500] if attempt.error else None,
                )
            )
    return metas


class PingRequest(BaseModel):
    targets: list[str] | None = Field(default=None, description="예: [\"openai:gpt-5.5\"]. 비우면 기본 순서")


class PingResponse(BaseModel):
    text: str
    generations: list[GenerationMeta]


@router.post("/ping")
async def ping(body: PingRequest, llm: Annotated[LLMRouter, Depends(get_router)]) -> PingResponse:
    try:
        route = [Target.parse(spec) for spec in body.targets] if body.targets else None
    except ValueError as error:
        raise HTTPException(status_code=422, detail=str(error)) from error

    request = LLMRequest(
        system="You are a connectivity check. Reply with exactly the word: pong",
        prompt="ping",
        max_tokens=2000,
    )
    try:
        outcome = await llm.generate(request, route)
    except AllTargetsFailed as failed:
        raise HTTPException(
            status_code=503,
            detail={"message": str(failed), "generations": [m.model_dump() for m in generation_meta(failed.outcome)]},
        ) from failed

    return PingResponse(text=outcome.result.text.strip(), generations=generation_meta(outcome))
