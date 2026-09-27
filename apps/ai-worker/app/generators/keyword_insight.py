import json

from pydantic import BaseModel, Field

from app.analyzers.aggregate import KeywordStats
from app.llm.router import LLMRouter, RouteOutcome
from app.llm.types import LLMRequest, Target
from app.prompts import load_prompt

PROMPT_VERSION = "keyword-analysis-v1"


class IntentShare(BaseModel):
    label: str
    share: float


class OutlineSection(BaseModel):
    heading: str
    purpose: str
    photo_hint: str


class KeywordInsight(BaseModel):
    primary_intent: str
    intent_distribution: list[IntentShare]
    must_answer: list[str] = Field(description="검색하는 사람이 글에서 답을 기대하는 질문")
    recommended_outline: list[OutlineSection]
    title_guidelines: list[str]
    related_keywords: list[str]
    writing_tips: list[str]


def build_request(keyword: str, category: str | None, stats: KeywordStats | None) -> LLMRequest:
    payload = {
        "keyword": keyword,
        "category": category,
        "reference_statistics": stats.model_dump() if stats else None,
    }
    return LLMRequest(
        system=load_prompt(PROMPT_VERSION),
        prompt=json.dumps(payload, ensure_ascii=False),
        output_model=KeywordInsight,
    )


async def generate_insight(
    router: LLMRouter, keyword: str, category: str | None, stats: KeywordStats | None, route: list[Target] | None = None
) -> RouteOutcome:
    return await router.generate(build_request(keyword, category, stats), route)
