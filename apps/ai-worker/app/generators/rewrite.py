import json
from typing import Literal

from pydantic import BaseModel

from app.generators.draft import DraftWarning, unsupported_specifics
from app.generators.writing_plan import TONE_LABELS, FactInput, Tone
from app.llm.router import LLMRouter, RouteOutcome
from app.llm.types import LLMRequest, Target
from app.prompts import load_prompt

PROMPT_VERSION = "paragraph-rewrite-v1"

Instruction = Literal["shorter", "longer", "natural", "rewrite"]


class RewriteInput(BaseModel):
    text: str
    instruction: Instruction
    tone: Tone = "natural"
    before: str | None = None
    after: str | None = None
    facts: list[FactInput] = []
    forbidden_claims: list[str] = []


class RewriteOutput(BaseModel):
    text: str


def build_request(data: RewriteInput) -> LLMRequest:
    payload = {
        "paragraph": data.text,
        "request": data.instruction,
        "tone": TONE_LABELS[data.tone],
        "paragraph_before": data.before,
        "paragraph_after": data.after,
        "facts": [f.model_dump() for f in data.facts],
        "forbidden_claims": data.forbidden_claims,
    }
    return LLMRequest(
        system=load_prompt(PROMPT_VERSION),
        prompt=json.dumps(payload, ensure_ascii=False),
        output_model=RewriteOutput,
        max_tokens=4000,
    )


def check_rewrite(text: str, data: RewriteInput) -> list[DraftWarning]:
    """원래 문단이나 사실에 없던 구체 정보가 새로 생겼는지만 본다."""
    sources = [*data.facts, FactInput(fact_key="원문", fact_value=data.text)]
    return unsupported_specifics(text, sources)


async def rewrite(router: LLMRouter, data: RewriteInput, route: list[Target] | None = None) -> RouteOutcome:
    return await router.generate(build_request(data), route)
