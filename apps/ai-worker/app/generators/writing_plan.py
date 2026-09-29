"""Writing Plan (기획서 6.1 STEP 1). LLM 결과를 코드로 다시 검증해 사실·사진 근거를 강제한다."""

import json
from typing import Literal

from pydantic import BaseModel

from app.llm.router import LLMRouter, RouteOutcome
from app.llm.types import LLMRequest, Platform, Target
from app.prompts import load_prompt

PROMPT_VERSION = "writing-plan-v3"

# 참고자료의 이 비율 이상이 다루는 정보를 사용자가 주지 않았으면 단정 금지로 추가한다
EXPECTED_SLOT_SHARE = 0.5
SLOT_FACT_HINTS: dict[str, tuple[str, list[str]]] = {
    "price": ("가격", ["가격", "비용", "요금", "원"]),
    "address": ("위치·주소", ["주소", "위치", "장소"]),
    "phone": ("연락처", ["전화", "연락처", "문의"]),
    "hours": ("영업시간", ["영업", "운영", "시간", "휴무"]),
    "parking": ("주차 가능 여부", ["주차"]),
    "reservation": ("예약 필요 여부", ["예약"]),
    "wait": ("웨이팅 여부", ["웨이팅", "대기"]),
    "menu": ("메뉴 구성", ["메뉴"]),
}

Tone = Literal["natural", "expert", "friendly", "clean"]
TONE_LABELS = {"natural": "자연스러운 후기", "expert": "전문 정보형", "friendly": "친근한 말투", "clean": "깔끔한 정보형"}


class FactInput(BaseModel):
    fact_key: str
    fact_value: str


class ImageVision(BaseModel):
    type: str
    description: str
    usable: bool = True
    suggested_section: str | None = None


class ImageInput(BaseModel):
    id: int
    sort_order: int
    taken_at: str | None = None
    vision: ImageVision | None = None


class PlanInput(BaseModel):
    platform: Platform = "naver"
    keyword: str
    category: str | None = None
    tone: Tone = "natural"
    target_length: int = 2500
    facts: list[FactInput]
    images: list[ImageInput] = []
    analysis: dict | None = None  # {"stats": ..., "insight": ...}


class PlanSection(BaseModel):
    heading: str
    purpose: str
    key_points: list[str]
    fact_keys: list[str]
    image_ids: list[int]


class PlanKeywords(BaseModel):
    primary: list[str]
    secondary: list[str]


class WritingPlan(BaseModel):
    title_candidates: list[str]
    search_intent: str
    outline: list[PlanSection]
    keywords: PlanKeywords
    required_fact_keys: list[str]
    forbidden_claims: list[str]


class CheckedPlan(WritingPlan):
    """코드 검증을 거친 계획. 검증에서 고친 내용을 함께 남긴다."""

    unused_fact_keys: list[str]
    unplaced_image_ids: list[int]
    corrections: list[str]


def build_request(data: PlanInput) -> LLMRequest:
    payload = {
        "platform": data.platform,
        "keyword": data.keyword,
        "category": data.category,
        "tone": TONE_LABELS[data.tone],
        "target_length": data.target_length,
        "facts": [f.model_dump() for f in data.facts],
        "photos": [i.model_dump() for i in sorted(data.images, key=lambda i: i.sort_order)],
        "analysis": data.analysis,
    }
    return LLMRequest(
        system=load_prompt(PROMPT_VERSION),
        prompt=json.dumps(payload, ensure_ascii=False),
        output_model=WritingPlan,
    )


def check_plan(plan: WritingPlan, data: PlanInput) -> CheckedPlan:
    fact_keys = [f.fact_key for f in data.facts]
    image_ids = {i.id for i in data.images}
    corrections: list[str] = []
    placed: set[int] = set()

    outline = []
    for section in plan.outline:
        facts = [key for key in dict.fromkeys(section.fact_keys) if key in fact_keys]
        if len(facts) != len(section.fact_keys):
            corrections.append(f"'{section.heading}'에서 입력에 없는 사실 항목을 뺐습니다.")
        images = []
        for image_id in section.image_ids:
            if image_id not in image_ids:
                corrections.append(f"'{section.heading}'에서 없는 사진({image_id})을 뺐습니다.")
            elif image_id in placed:
                corrections.append(f"사진 {image_id}이 여러 섹션에 있어 처음 위치만 남겼습니다.")
            else:
                placed.add(image_id)
                images.append(image_id)
        outline.append(section.model_copy(update={"fact_keys": facts, "image_ids": images}))

    required = [key for key in dict.fromkeys(plan.required_fact_keys) if key in fact_keys]
    if len(required) != len(set(plan.required_fact_keys)):
        corrections.append("반드시 넣을 사실에서 입력에 없는 항목을 뺐습니다.")
    # 사용자가 준 사실은 항상 반드시 넣을 사실이다
    required += [key for key in fact_keys if key not in required]

    used = {key for section in outline for key in section.fact_keys}
    ordered_images = [i.id for i in sorted(data.images, key=lambda i: i.sort_order)]

    return CheckedPlan(
        title_candidates=[t.strip() for t in dict.fromkeys(plan.title_candidates) if t.strip()][:5],
        search_intent=plan.search_intent,
        outline=outline,
        keywords=plan.keywords,
        required_fact_keys=required,
        forbidden_claims=_merge(plan.forbidden_claims, missing_expected_facts(data)),
        unused_fact_keys=[key for key in fact_keys if key not in used],
        unplaced_image_ids=[image_id for image_id in ordered_images if image_id not in placed],
        corrections=corrections,
    )


def missing_expected_facts(data: PlanInput) -> list[str]:
    """참고자료 대부분이 다루는데 사용자가 주지 않은 정보 → 단정 금지 지시."""
    stats = (data.analysis or {}).get("stats") or {}
    provided = " ".join(f"{f.fact_key} {f.fact_value}" for f in data.facts)
    claims = []
    for slot in stats.get("slots", []):
        hint = SLOT_FACT_HINTS.get(slot.get("label"))
        if hint and slot.get("share", 0) >= EXPECTED_SLOT_SHARE:
            label, words = hint
            if not any(word in provided for word in words):
                claims.append(f"{label}는 입력되지 않았으므로 단정하지 말 것")
    return claims


def _merge(llm_claims: list[str], code_claims: list[str]) -> list[str]:
    return list(dict.fromkeys([*code_claims, *(c.strip() for c in llm_claims if c.strip())]))


async def generate_plan(router: LLMRouter, data: PlanInput, route: list[Target] | None = None) -> RouteOutcome:
    return await router.generate(build_request(data), route)
