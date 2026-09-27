from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel

from app.api.llm import GenerationMeta, generation_meta
from app.generators import draft as drafts
from app.generators import rewrite as rewrites
from app.generators.draft import CheckedDraft, DraftInput, DraftWarning, check_draft, generate_draft
from app.generators.rewrite import RewriteInput, check_rewrite
from app.quality.gate import QualityInput, QualityReport, check
from app.generators.writing_plan import PROMPT_VERSION, CheckedPlan, PlanInput, check_plan, generate_plan
from app.llm.factory import get_router
from app.llm.router import AllTargetsFailed, LLMRouter
from app.llm.types import Target

router = APIRouter(prefix="/posts")


class PlanRequest(PlanInput):
    targets: list[str] | None = None


class PlanResponse(BaseModel):
    plan: CheckedPlan | None
    plan_error: str | None
    prompt_version: str
    generations: list[GenerationMeta]


@router.post("/plan")
async def plan(body: PlanRequest, llm: Annotated[LLMRouter, Depends(get_router)]) -> PlanResponse:
    if not body.facts:
        raise HTTPException(status_code=422, detail="사실 정보가 1개 이상 필요합니다.")
    try:
        route = [Target.parse(spec) for spec in body.targets] if body.targets else None
    except ValueError as error:
        raise HTTPException(status_code=422, detail=str(error)) from error

    data = PlanInput.model_validate(body.model_dump(exclude={"targets"}))
    try:
        outcome = await generate_plan(llm, data, route)
    except AllTargetsFailed as failed:
        kinds = sorted({a.error.kind for a in failed.outcome.attempts if a.error})
        return PlanResponse(
            plan=None,
            plan_error="글 계획을 만들지 못했습니다: " + ", ".join(kinds),
            prompt_version=PROMPT_VERSION,
            generations=generation_meta(failed.outcome),
        )

    return PlanResponse(
        plan=check_plan(outcome.result.parsed, data),
        plan_error=None,
        prompt_version=PROMPT_VERSION,
        generations=generation_meta(outcome),
    )


class DraftRequest(DraftInput):
    targets: list[str] | None = None


class DraftResponse(BaseModel):
    draft: CheckedDraft | None
    draft_error: str | None
    prompt_version: str
    generations: list[GenerationMeta]


@router.post("/draft")
async def draft(body: DraftRequest, llm: Annotated[LLMRouter, Depends(get_router)]) -> DraftResponse:
    if not body.plan.get("outline"):
        raise HTTPException(status_code=422, detail="글 계획(목차)이 필요합니다.")
    try:
        route = [Target.parse(spec) for spec in body.targets] if body.targets else None
    except ValueError as error:
        raise HTTPException(status_code=422, detail=str(error)) from error

    data = DraftInput.model_validate(body.model_dump(exclude={"targets"}))
    try:
        outcome = await generate_draft(llm, data, route)
    except AllTargetsFailed as failed:
        kinds = sorted({a.error.kind for a in failed.outcome.attempts if a.error})
        return DraftResponse(
            draft=None,
            draft_error="초안을 만들지 못했습니다: " + ", ".join(kinds),
            prompt_version=drafts.PROMPT_VERSION,
            generations=generation_meta(failed.outcome),
        )

    return DraftResponse(
        draft=check_draft(outcome.result.parsed, data),
        draft_error=None,
        prompt_version=drafts.PROMPT_VERSION,
        generations=generation_meta(outcome),
    )


class RewriteRequest(RewriteInput):
    targets: list[str] | None = None


class RewriteResponse(BaseModel):
    text: str | None
    warnings: list[DraftWarning]
    error: str | None
    prompt_version: str
    generations: list[GenerationMeta]


@router.post("/rewrite")
async def rewrite(body: RewriteRequest, llm: Annotated[LLMRouter, Depends(get_router)]) -> RewriteResponse:
    if not body.text.strip():
        raise HTTPException(status_code=422, detail="다시 쓸 문단이 비어 있습니다.")
    try:
        route = [Target.parse(spec) for spec in body.targets] if body.targets else None
    except ValueError as error:
        raise HTTPException(status_code=422, detail=str(error)) from error

    data = RewriteInput.model_validate(body.model_dump(exclude={"targets"}))
    try:
        outcome = await rewrites.rewrite(llm, data, route)
    except AllTargetsFailed as failed:
        kinds = sorted({a.error.kind for a in failed.outcome.attempts if a.error})
        return RewriteResponse(
            text=None, warnings=[], error="문단을 다시 쓰지 못했습니다: " + ", ".join(kinds),
            prompt_version=rewrites.PROMPT_VERSION, generations=generation_meta(failed.outcome),
        )

    text = outcome.result.parsed.text.strip()
    return RewriteResponse(
        text=text, warnings=check_rewrite(text, data), error=None,
        prompt_version=rewrites.PROMPT_VERSION, generations=generation_meta(outcome),
    )


@router.post("/quality-check")
def quality_check(body: QualityInput) -> QualityReport:
    return check(body)
