"""초안 생성 (기획서 6.1 STEP 2) + 코드 검증(사진 배치, 입력에 없는 구체 정보, 사실 반영, 길이)."""

import json
import re
from typing import Literal

from pydantic import BaseModel

from app.analyzers.exposure import merge_tags
from app.generators.writing_plan import TONE_LABELS, FactInput, Speech, Tone
from app.llm.router import LLMRouter, RouteOutcome
from app.llm.types import LLMRequest, Platform, Target
from app.prompts import load_prompt

PROMPT_VERSION = "blog-draft-v7"
LENGTH_TOLERANCE = (0.7, 1.4)

# 입력 사실에 없으면 지어낸 것으로 의심하는 구체 정보
SPECIFIC_PATTERNS = {
    "가격": r"\d[\d,]*\s*원|\d+(?:\.\d+)?\s*만\s*원",
    "시간": r"\d{1,2}\s*:\s*\d{2}|(?:오전|오후)\s*\d{1,2}\s*시|\d{1,2}\s*시\s*\d{1,2}\s*분",
    "전화번호": r"\d{2,4}-\d{3,4}-\d{4}",
    "주소": r"[가-힣]+(?:로|길)\s*\d+(?:-\d+)?|\d+\s*번지",
}


class DraftBlock(BaseModel):
    type: Literal["paragraph", "image", "list", "quote"]
    text: str | None = None
    image_id: int | None = None
    items: list[str] | None = None


class DraftSection(BaseModel):
    heading: str
    blocks: list[DraftBlock]


class Draft(BaseModel):
    title: str
    intro: list[DraftBlock]
    sections: list[DraftSection]
    closing: list[DraftBlock]
    tags: list[str]


class DraftInput(BaseModel):
    platform: Platform = "naver"
    # 같은 경험을 다른 플랫폼에도 따로 올린다(중복 문서로 보이지 않게 다르게 쓴다)
    twin: bool = False
    # info: 정보 전달 글, daily: 일상 기록(하루를 시간 순서로, 정보는 이야기 속에 지나가듯)
    mode: Literal["info", "daily"] = "info"
    speech: Speech = "auto"
    keyword: str
    tone: Tone = "natural"
    target_length: int = 2500
    title: str
    facts: list[FactInput]
    plan: dict
    image_ids: list[int]
    # 참고 글들의 말투 분포(키워드 분석 stats.voice). 있으면 그 리듬·어미·감탄 빈도에 맞춰 쓴다
    voice: dict | None = None
    # 사진 분석 결과(있으면): {id: {"type": ..., "description": ...}}
    photo_notes: dict[int, dict] = {}
    # 키워드 분석의 추천 해시태그(사용자가 고친 목록이 있으면 그것). 초안 태그 앞에 붙인다
    hashtags: list[str] = []


class ContentBlock(BaseModel):
    """편집기(TipTap)에 올리는 평평한 블록 목록. heading은 섹션 소제목."""

    type: Literal["heading", "paragraph", "image", "list", "quote"]
    text: str | None = None
    image_id: int | None = None
    items: list[str] | None = None


class DraftWarning(BaseModel):
    code: Literal["unsupported_specific", "fact_missing", "length", "image_unplaced", "image_invalid"]
    message: str


class CheckedDraft(BaseModel):
    title: str
    blocks: list[ContentBlock]
    tags: list[str]
    text: str
    char_count: int
    target_length: int
    keyword_count: int
    warnings: list[DraftWarning]


def build_request(data: DraftInput) -> LLMRequest:
    payload = {
        "platform": data.platform,
        "twin": data.twin,
        "mode": data.mode,
        "speech": data.speech,
        "voice": data.voice,
        "keyword": data.keyword,
        "tone": TONE_LABELS[data.tone],
        "target_length": data.target_length,
        "title": data.title,
        "facts": [f.model_dump() for f in data.facts],
        "plan": {k: data.plan.get(k) for k in ("outline", "keywords", "required_fact_keys", "forbidden_claims", "search_intent")},
        "photos": [
            {"id": image_id, "order": order + 1, **_note(data.photo_notes.get(image_id))}
            for order, image_id in enumerate(data.image_ids)
        ],
    }
    return LLMRequest(
        system=load_prompt(PROMPT_VERSION),
        prompt=json.dumps(payload, ensure_ascii=False),
        output_model=Draft,
    )


def _note(note: dict | None) -> dict:
    return {k: note[k] for k in ("type", "description") if note and note.get(k)}


def check_draft(draft: Draft, data: DraftInput) -> CheckedDraft:
    warnings: list[DraftWarning] = []
    valid_ids = set(data.image_ids)
    placed: set[int] = set()

    def convert(block: DraftBlock) -> ContentBlock | None:
        if block.type == "image":
            if block.image_id not in valid_ids or block.image_id in placed:
                warnings.append(DraftWarning(code="image_invalid", message=f"잘못되거나 중복된 사진({block.image_id})을 뺐습니다."))
                return None
            placed.add(block.image_id)
            return ContentBlock(type="image", image_id=block.image_id)
        if block.type == "list":
            items = [i.strip() for i in (block.items or []) if i.strip()]
            return ContentBlock(type="list", items=items) if items else None
        text = (block.text or "").strip()
        return ContentBlock(type=block.type, text=text) if text else None

    blocks: list[ContentBlock] = [b for b in map(convert, draft.intro) if b]
    for section in draft.sections:
        blocks.append(ContentBlock(type="heading", text=section.heading.strip()))
        blocks.extend(b for b in map(convert, section.blocks) if b)
    blocks.extend(b for b in map(convert, draft.closing) if b)

    unplaced = [image_id for image_id in data.image_ids if image_id not in placed]
    if unplaced:
        warnings.append(DraftWarning(code="image_unplaced", message=f"초안에 들어가지 않은 사진이 {len(unplaced)}장 있습니다."))

    title = draft.title.strip()
    text = render_text(title, blocks)
    body = render_text(None, blocks)
    char_count = len(body.replace("\n", ""))
    low, high = LENGTH_TOLERANCE
    if not low * data.target_length <= char_count <= high * data.target_length:
        warnings.append(DraftWarning(code="length", message=f"글자 수 {char_count}자가 목표 {data.target_length}자와 차이가 큽니다."))

    warnings.extend(unsupported_specifics(body, data.facts))
    warnings.extend(missing_facts(body, data.facts, data.plan.get("required_fact_keys") or []))

    # 추천 해시태그를 먼저, AI가 글에 맞춰 단 태그로 채운다(네이버 태그는 최대 30개)
    tags = merge_tags(data.hashtags, draft.tags, limit=min(30, max(15, len(data.hashtags))))
    keyword = " ".join(data.keyword.split())
    return CheckedDraft(
        title=title,
        blocks=blocks,
        tags=tags,
        text=text,
        char_count=char_count,
        target_length=data.target_length,
        keyword_count=body.count(keyword) if keyword else 0,
        warnings=warnings,
    )


def render_text(title: str | None, blocks: list[ContentBlock]) -> str:
    lines = [title] if title else []
    for block in blocks:
        if block.type == "list":
            lines.extend(f"- {item}" for item in block.items or [])
        elif block.type != "image":
            lines.append(block.text or "")
    return "\n".join(lines)


def _compact(value: str) -> str:
    return re.sub(r"[\s,]", "", value)


def unsupported_specifics(body: str, facts: list[FactInput]) -> list[DraftWarning]:
    """본문의 가격·시간·전화·주소 표현이 사용자 사실에 없으면 경고한다(Hallucination Gate 1차)."""
    source = _compact(" ".join(f.fact_value for f in facts))
    warnings = []
    for label, pattern in SPECIFIC_PATTERNS.items():
        for match in dict.fromkeys(m.group(0) for m in re.finditer(pattern, body)):
            if _compact(match) not in source:
                warnings.append(DraftWarning(code="unsupported_specific", message=f"입력하지 않은 {label} 정보: {match}"))
    return warnings


def missing_facts(body: str, facts: list[FactInput], required_keys: list[str]) -> list[DraftWarning]:
    """숫자가 든 사실은 그 숫자가 본문에 있어야 반영된 것으로 본다(문장형 사실은 Day 12에서 판정)."""
    compact_body = _compact(body)
    warnings = []
    for fact in facts:
        if fact.fact_key not in required_keys:
            continue
        numbers = re.findall(r"\d[\d,.]*", fact.fact_value)
        if numbers and not all(_compact(n) in compact_body for n in numbers):
            warnings.append(DraftWarning(code="fact_missing", message=f"'{fact.fact_key}' 값({fact.fact_value})이 본문에 그대로 없습니다."))
    return warnings


async def generate_draft(router: LLMRouter, data: DraftInput, route: list[Target] | None = None) -> RouteOutcome:
    # 비스트리밍 요청은 max_tokens 16000(기본값)을 넘기지 않는다(SDK 타임아웃 가드). 4,000자 글에 충분하다.
    return await router.generate(build_request(data), route)
