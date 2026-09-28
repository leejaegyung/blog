"""참고자료 특징(DocumentFeatures) 여러 개를 키워드 단위 통계로 모은다. LLM 없이 코드로만 계산한다.

이 값은 "등록한 참고자료의 분포"이지 검색 순위 예측이 아니다(기획서 16장).
"""

import re
from collections import Counter
from statistics import median

from pydantic import BaseModel

from app.analyzers.features import SLOT_PATTERNS, DocumentFeatures

STATS_VERSION = "stats-1"


class Spread(BaseModel):
    median: float
    p25: float
    p75: float


class Share(BaseModel):
    label: str
    share: float  # 0~1


class TermFrequency(BaseModel):
    term: str
    documents: int  # 이 단어가 나온 참고자료 수
    share: float


class PhotoStats(BaseModel):
    count: Spread
    starts_with_photo_share: float
    intro_images: Spread
    max_group_size: Spread
    paragraphs_between_groups: Spread | None
    per_1000_chars: Spread


class TitleStats(BaseModel):
    length: Spread
    keyword_position: list[Share]
    with_brackets_share: float
    with_number_share: float
    with_question_share: float


class KeywordStats(BaseModel):
    version: str = STATS_VERSION
    reference_count: int
    char_count: Spread
    heading_count: Spread
    paragraph_count: Spread
    photos: PhotoStats
    title: TitleStats | None
    keyword_per_1000_chars: Spread
    keyword_in_first_paragraph_share: float
    slots: list[Share]
    topics: list[TermFrequency]  # 소제목에 자주 쓰인 명사
    terms: list[TermFrequency]  # 본문에 자주 쓰인 명사
    opening_patterns: list[Share]  # 글 시작 구성(예: "사진→문단→소제목")
    intro_types: list[Share]
    ending_summary_share: float
    ending_recommendation_share: float
    ending_engagement_share: float
    # 참고 글에 달린 해시태그(나중에 더한 항목)
    hashtags: list[TermFrequency] = []
    hashtag_count: Spread | None = None  # 해시태그를 단 글들의 태그 수


LAYOUT_NAMES = {"H": "소제목", "P": "문단", "I": "사진", "L": "목록", "Q": "인용", "T": "표"}


def aggregate(features: list[DocumentFeatures], keyword: str) -> KeywordStats | None:
    if not features:
        return None
    n = len(features)
    titles = [f.title for f in features if f.title]
    gaps = [f.photos.avg_paragraphs_between_groups for f in features if f.photos.avg_paragraphs_between_groups is not None]
    keyword_tokens = set(keyword.split())

    return KeywordStats(
        reference_count=n,
        char_count=_spread([f.char_count for f in features]),
        heading_count=_spread([f.heading_count for f in features]),
        paragraph_count=_spread([f.paragraph_count for f in features]),
        photos=PhotoStats(
            count=_spread([f.photos.count for f in features]),
            starts_with_photo_share=_share(sum(f.layout.startswith("I") for f in features), n),
            intro_images=_spread([f.photos.intro_images for f in features]),
            max_group_size=_spread([f.photos.max_group_size for f in features]),
            paragraphs_between_groups=_spread(gaps) if gaps else None,
            per_1000_chars=_spread([f.photos.per_1000_chars for f in features]),
        ),
        title=TitleStats(
            length=_spread([t.length for t in titles]),
            keyword_position=_distribution([t.keyword_position for t in titles]),
            with_brackets_share=_share(sum(t.has_brackets for t in titles), len(titles)),
            with_number_share=_share(sum(t.has_number for t in titles), len(titles)),
            with_question_share=_share(sum(t.has_question for t in titles), len(titles)),
        ) if titles else None,
        keyword_per_1000_chars=_spread([f.keyword.per_1000_chars for f in features]),
        keyword_in_first_paragraph_share=_share(sum(f.keyword.in_first_paragraph for f in features), n),
        slots=sorted(
            (Share(label=slot, share=_share(sum(f.slots.get(slot, False) for f in features), n)) for slot in SLOT_PATTERNS),
            key=lambda s: -s.share,
        ),
        topics=_document_frequency([f.heading_nouns for f in features], n, exclude=keyword_tokens, limit=15),
        terms=_document_frequency([[term for term, _ in f.top_nouns] for f in features], n, exclude=keyword_tokens, limit=25),
        opening_patterns=_distribution([_opening(f.layout) for f in features], limit=5),
        intro_types=_distribution([f.intro_type for f in features]),
        ending_summary_share=_share(sum(f.ending.has_summary for f in features), n),
        ending_recommendation_share=_share(sum(f.ending.has_recommendation for f in features), n),
        ending_engagement_share=_share(sum(f.ending.asks_engagement for f in features), n),
        hashtags=_document_frequency([f.hashtags for f in features], n, exclude=set(), limit=30),
        hashtag_count=_spread(counts) if (counts := [len(f.hashtags) for f in features if f.hashtags]) else None,
    )


def _spread(values: list[float]) -> Spread:
    ordered = sorted(values)
    return Spread(
        median=round(median(ordered), 2),
        p25=round(_percentile(ordered, 0.25), 2),
        p75=round(_percentile(ordered, 0.75), 2),
    )


def _percentile(ordered: list[float], q: float) -> float:
    position = (len(ordered) - 1) * q
    low = int(position)
    high = min(low + 1, len(ordered) - 1)
    return ordered[low] + (ordered[high] - ordered[low]) * (position - low)


def _share(count: int, total: int) -> float:
    return round(count / total, 3) if total else 0.0


def _distribution(labels: list[str], limit: int | None = None) -> list[Share]:
    counts = Counter(labels)
    return [Share(label=label, share=_share(count, len(labels))) for label, count in counts.most_common(limit)]


def _document_frequency(term_lists: list[list[str]], n: int, exclude: set[str], limit: int) -> list[TermFrequency]:
    counts = Counter(term for terms in term_lists for term in set(terms) if term not in exclude)
    # 참고자료가 여럿이면 한 글에만 나온 단어는 공통 주제로 보지 않는다
    minimum = 2 if n >= 3 else 1
    return [
        TermFrequency(term=term, documents=count, share=_share(count, n))
        for term, count in counts.most_common()
        if count >= minimum
    ][:limit]


def _opening(layout: str) -> str:
    # 같은 블록이 이어지면 하나로 보고 앞 3단계만 본다: "IIPPH..." → "사진→문단→소제목"
    collapsed = re.sub(r"(.)\1+", r"\1", layout)[:3]
    return "→".join(LAYOUT_NAMES[code] for code in collapsed) or "없음"
