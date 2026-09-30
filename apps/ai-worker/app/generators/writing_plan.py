"""Writing Plan (기획서 6.1 STEP 1). LLM 결과를 코드로 다시 검증해 사실·사진 근거를 강제한다."""

import json
from datetime import datetime
from typing import Literal

from pydantic import BaseModel

from app.llm.router import LLMRouter, RouteOutcome
from app.llm.types import LLMRequest, Platform, Target
from app.prompts import load_prompt

PROMPT_VERSION = "writing-plan-v5"

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
    # 같은 경험을 다른 플랫폼에도 따로 올린다(중복 문서로 보이지 않게 다르게 쓴다)
    twin: bool = False
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


def _shot_time(image: ImageInput) -> datetime | None:
    """카메라가 적은 찍은 시각(현지 시각). 저장·전달 중 붙은 시간대는 떼고 벽시계 시각만 쓴다"""
    if not image.taken_at:
        return None
    try:
        return datetime.fromisoformat(image.taken_at).replace(tzinfo=None)
    except ValueError:
        return None


def photo_payload(images: list[ImageInput]) -> list[dict]:
    """사진 순서 판단 재료: 올린 순서(기본 나열 순서) + 찍은 시간 순서 + 사진 분석 결과. 최종 배치는 AI가 셋을 함께 보고 정한다"""
    by_upload = sorted(images, key=lambda i: i.sort_order)
    times = {i.id: t for i in by_upload if (t := _shot_time(i))}
    # 찍은 시각이 같으면 올린 순서를 따른다
    shot_rank = {image_id: rank for rank, image_id in enumerate(sorted(times, key=lambda k: (times[k], [i.id for i in by_upload].index(k))), start=1)}
    first = min(times.values()) if times else None
    photos = []
    for upload_order, image in enumerate(by_upload, start=1):
        photo: dict = {"id": image.id, "upload_order": upload_order}
        if (shot := times.get(image.id)) and first:
            photo |= {
                "taken_at": shot.strftime("%Y-%m-%d %H:%M"),
                "shot_order": shot_rank[image.id],
                "minutes_after_first_shot": round((shot - first).total_seconds() / 60),
            }
        if image.vision:
            photo["vision"] = image.vision.model_dump()
        photos.append(photo)
    return photos


def build_request(data: PlanInput) -> LLMRequest:
    payload = {
        "platform": data.platform,
        "twin": data.twin,
        "keyword": data.keyword,
        "category": data.category,
        "tone": TONE_LABELS[data.tone],
        "target_length": data.target_length,
        "facts": [f.model_dump() for f in data.facts],
        "photos": photo_payload(data.images),
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
