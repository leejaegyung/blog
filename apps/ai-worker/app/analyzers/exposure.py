"""검색 노출 가이드와 추천 해시태그. LLM 없이 코드로 계산한다.

네이버는 순위 기준을 공개하지 않는다. 여기 목표치는 "사용자가 고른 참고 글의 분포"와 네이버가 공개한 검색 원칙
(경험·정보가 충실한 문서 우대, 어뷰징·유사문서 제외)에 근거한 가이드이지 순위 보장이 아니다.
"""

import re
from typing import Literal

from pydantic import BaseModel

from app.analyzers.aggregate import KeywordStats

GUIDE_VERSION = "guide-1"
MAX_HASHTAGS = 20
REFERENCE_TAG_SHARE = 0.3  # 참고 글 30% 이상이 단 태그만 추천에 넣는다
DEFAULT_HASHTAGS = (5, 15)

SLOT_LABELS = {
    "price": "가격", "address": "주소·위치", "phone": "연락처", "hours": "영업시간", "parking": "주차",
    "reservation": "예약", "wait": "웨이팅", "menu": "메뉴", "pros": "좋았던 점", "cons": "아쉬운 점",
    "recommend": "추천 대상",
}

# 네이버가 공개한 검색 원칙(C-Rank·D.I.A. 소개, 검색 품질 정책)을 블로거가 할 일로 옮긴 것
PRINCIPLES = [
    "직접 가 보고 찍은 사진과 경험을 중심으로 쓰세요. 네이버는 경험과 정보가 충실한 글을 우대한다고 밝혀 왔어요.",
    "키워드를 억지로 반복하지 마세요. 과한 반복은 어뷰징으로 보고 노출에서 불리할 수 있어요.",
    "다른 글을 베끼거나 짜깁기하지 마세요. 비슷한 문서는 유사 문서로 묶여 노출되지 않을 수 있어요.",
    "한 주제로 꾸준히 쓰세요. 주제 전문성과 꾸준함이 블로그 신뢰도에 반영돼요.",
    "해시태그는 글과 관련된 것만 다세요. 상관없는 인기 태그는 도움이 되지 않아요.",
    "협찬·광고를 받았다면 글 첫머리에 분명히 밝히세요.",
]

TAG_CHARS = re.compile(r"[^0-9A-Za-z가-힣_]")


class GuideTarget(BaseModel):
    key: str
    label: str
    target: str
    basis: str
    min: float | None = None
    max: float | None = None


class GuideChecks(BaseModel):
    """품질 검사가 쓰는 기준(참고 글이 없으면 일반 기준)."""

    title_keyword_start: bool
    keyword_in_first_paragraph: bool
    photo_min: int
    heading_min: int
    hashtag_min: int
    hashtag_max: int


class HashtagSuggestion(BaseModel):
    tag: str
    source: Literal["keyword", "references", "ai"]
    share: float | None = None  # 참고 글 중 이 태그를 단 비율


class ExposureGuide(BaseModel):
    version: str = GUIDE_VERSION
    reference_count: int
    targets: list[GuideTarget]
    principles: list[str]
    hashtags: list[HashtagSuggestion]
    checks: GuideChecks


def normalize_tag(tag: str) -> str | None:
    cleaned = TAG_CHARS.sub("", tag.strip().lstrip("#"))
    if not cleaned or cleaned.isdigit() or len(cleaned) > 30:
        return None
    return cleaned


def merge_tags(*groups: list[str], limit: int = 30) -> list[str]:
    """앞 그룹을 우선해 정규화·중복 제거(대소문자 무시)한다."""
    seen: dict[str, str] = {}
    for group in groups:
        for raw in group:
            tag = normalize_tag(raw)
            if tag and tag.lower() not in seen:
                seen[tag.lower()] = tag
    return list(seen.values())[:limit]


def recommend_hashtags(
    keyword: str, category: str | None, stats: KeywordStats | None, ai_tags: list[str] | None = None
) -> list[HashtagSuggestion]:
    suggestions: dict[str, HashtagSuggestion] = {}

    def add(raw: str, source: Literal["keyword", "references", "ai"], share: float | None = None) -> None:
        tag = normalize_tag(raw)
        if tag and tag.lower() not in suggestions and len(suggestions) < MAX_HASHTAGS:
            suggestions[tag.lower()] = HashtagSuggestion(tag=tag, source=source, share=share)

    tokens = keyword.split()
    add("".join(tokens), "keyword")
    # "수원 인계동 파스타" → 인계동파스타, 수원파스타 (지역·대상 + 주제)
    head, last = tokens[:-1], tokens[-1] if tokens else ""
    for token in reversed(head):
        add(token + last, "keyword")
    suffix = normalize_tag(category or "")
    if suffix and len(suffix) <= 4 and suffix not in keyword.replace(" ", ""):
        for token in reversed(head or tokens):
            add(token + suffix, "keyword")

    if stats:
        for term in stats.hashtags:
            if term.share >= REFERENCE_TAG_SHARE or stats.reference_count == 1:
                add(term.term, "references", term.share)

    for tag in ai_tags or []:
        add(tag, "ai")
    return list(suggestions.values())


def build_guide(
    keyword: str, category: str | None, stats: KeywordStats | None, ai_tags: list[str] | None = None
) -> ExposureGuide:
    hashtags = recommend_hashtags(keyword, category, stats, ai_tags)
    if not stats:
        return _default_guide(hashtags)

    n = stats.reference_count
    basis = f"참고 글 {n}개"
    targets: list[GuideTarget] = []

    title_start = False
    if stats.title:
        start = next((s.share for s in stats.title.keyword_position if s.label == "start"), 0.0)
        title_start = start >= 0.4
        targets.append(GuideTarget(
            key="title_keyword", label="제목",
            target="키워드로 제목을 시작하기" if title_start else "제목에 키워드 넣기",
            basis=f"{basis} 중 {_pct(start)}가 키워드로 제목을 시작해요",
        ))
        low, high = _range(stats.title.length.p25, stats.title.length.p75)
        targets.append(GuideTarget(
            key="title_length", label="제목 길이", target=_span(low, high, "자"), basis=f"{basis} 제목 길이의 가운데 50%",
            min=low, max=high,
        ))

    low, high = _range(stats.char_count.p25, stats.char_count.p75, step=100)
    targets.append(GuideTarget(
        key="length", label="본문 길이", target=_span(low, high, "자"), basis=f"{basis} 본문 길이의 가운데 50%", min=low, max=high,
    ))

    photo_low, photo_high = _range(stats.photos.count.p25, stats.photos.count.p75)
    if photo_high > 0:
        targets.append(GuideTarget(
            key="photos", label="사진", target=f"직접 찍은 사진 {_span(photo_low, photo_high, '장')}",
            basis=f"{basis} 사진 수의 가운데 50%", min=photo_low, max=photo_high,
        ))

    heading_low, heading_high = _range(stats.heading_count.p25, stats.heading_count.p75)
    if heading_high > 0:
        targets.append(GuideTarget(
            key="headings", label="소제목", target=_span(heading_low, heading_high, "개"),
            basis=f"{basis} 소제목 수의 가운데 50%", min=heading_low, max=heading_high,
        ))

    per_1000 = stats.keyword_per_1000_chars
    chars = stats.char_count.median / 1000
    repeat_low, repeat_high = max(1, round(per_1000.p25 * chars)), max(1, round(per_1000.p75 * chars))
    targets.append(GuideTarget(
        key="keyword_repeat", label="키워드 반복", target=f"본문에 {_span(repeat_low, repeat_high, '번')}(억지 반복은 금물)",
        basis=f"{basis}의 1000자당 키워드 수를 본문 길이에 맞춘 값", min=repeat_low, max=repeat_high,
    ))

    first_paragraph = stats.keyword_in_first_paragraph_share >= 0.5
    if first_paragraph:
        targets.append(GuideTarget(
            key="first_paragraph", label="첫 문단", target="첫 문단에 키워드 넣기",
            basis=f"{basis} 중 {_pct(stats.keyword_in_first_paragraph_share)}가 첫 문단에 키워드를 넣었어요",
        ))

    slots = [s for s in stats.slots if s.share >= 0.5 and s.label in SLOT_LABELS]
    if slots:
        targets.append(GuideTarget(
            key="info_items", label="꼭 담을 정보", target=" · ".join(SLOT_LABELS[s.label] for s in slots),
            basis=f"{basis}의 절반 이상이 다룬 정보(직접 확인한 것만 쓰세요)",
        ))

    if stats.hashtag_count:
        tag_low, tag_high = _range(stats.hashtag_count.p25, stats.hashtag_count.p75)
        tag_low, tag_high = max(tag_low, 3), min(max(tag_high, tag_low), 30)
        tag_basis = "해시태그를 단 참고 글의 태그 수 가운데 50%"
    else:
        (tag_low, tag_high), tag_basis = DEFAULT_HASHTAGS, "참고 글에 해시태그가 없어 일반 기준이에요"
    targets.append(GuideTarget(
        key="hashtags", label="해시태그", target=_span(tag_low, tag_high, "개"), basis=tag_basis, min=tag_low, max=tag_high,
    ))

    if stats.ending_summary_share >= 0.5:
        targets.append(GuideTarget(
            key="ending", label="마무리", target="마지막에 총평·정리 넣기",
            basis=f"{basis} 중 {_pct(stats.ending_summary_share)}가 정리로 끝나요",
        ))

    return ExposureGuide(
        reference_count=n,
        targets=targets,
        principles=PRINCIPLES,
        hashtags=hashtags,
        checks=GuideChecks(
            title_keyword_start=title_start,
            keyword_in_first_paragraph=first_paragraph,
            photo_min=photo_low,
            heading_min=heading_low,
            hashtag_min=tag_low,
            hashtag_max=tag_high if stats.hashtag_count else 30,
        ),
    )


def _default_guide(hashtags: list[HashtagSuggestion]) -> ExposureGuide:
    basis = "참고 글이 없어 일반 기준이에요. 참고 글을 추가하면 이 키워드에 맞춰 바뀌어요"
    low, high = DEFAULT_HASHTAGS
    return ExposureGuide(
        reference_count=0,
        targets=[
            GuideTarget(key="title_keyword", label="제목", target="키워드로 제목을 시작하기", basis=basis),
            GuideTarget(key="length", label="본문 길이", target="1,500자 이상", basis=basis, min=1500),
            GuideTarget(key="photos", label="사진", target="직접 찍은 사진 5장 이상", basis=basis, min=5),
            GuideTarget(key="headings", label="소제목", target="3개 이상", basis=basis, min=3),
            GuideTarget(key="first_paragraph", label="첫 문단", target="첫 문단에 키워드 넣기", basis=basis),
            GuideTarget(key="hashtags", label="해시태그", target=f"{low}~{high}개", basis=basis, min=low, max=high),
        ],
        principles=PRINCIPLES,
        hashtags=hashtags,
        checks=GuideChecks(
            title_keyword_start=True, keyword_in_first_paragraph=True, photo_min=5, heading_min=3,
            hashtag_min=low, hashtag_max=30,
        ),
    )


def _range(low: float, high: float, step: int = 1) -> tuple[int, int]:
    a, b = round(low / step) * step, round(high / step) * step
    return int(a), int(max(a, b))


def _span(low: int, high: int, unit: str) -> str:
    """참고 글이 하나뿐이면 범위 대신 한 값으로("16~16자" → "16자")."""
    return f"{low:,}{unit}" if low == high else f"{low:,}~{high:,}{unit}"


def _pct(share: float) -> str:
    return f"{round(share * 100)}%"
