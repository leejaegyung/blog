"""참고 글에서 코드로 뽑는 특징 (기획서 4.2). 원문 문장은 결과에 넣지 않는다.

키워드 분석(Day 7)은 원문 없이 이 결과만으로 동작해야 한다.
"""

import re
from collections import Counter
from typing import Literal

from pydantic import BaseModel

from app.analyzers.nlp import nouns, sentences
from app.analyzers.style import StyleFeatures, extract_style
from app.references.document import Block, ParsedDocument

FEATURES_VERSION = "features-1"

LAYOUT_CODES = {"heading": "H", "paragraph": "P", "image": "I", "list": "L", "quote": "Q", "table": "T"}

# 글이 다루는 정보 항목. 검색 의도·필수 답변 항목(must_answer)의 근거가 된다.
SLOT_PATTERNS = {
    "price": r"\d[\d,]*\s*원|\d+\s*만\s*원|가격|비용",
    "address": r"주소|위치|[가-힣]+(시|구|군)\s+[가-힣0-9]+(동|로|길)",
    "phone": r"\d{2,4}-\d{3,4}-\d{4}|전화|문의",
    "hours": r"영업\s*시간|운영\s*시간|브레이크\s*타임|휴무|\d{1,2}\s*:\s*\d{2}\s*[~\-]",
    "parking": r"주차",
    "reservation": r"예약",
    "wait": r"웨이팅|대기",
    "menu": r"메뉴",
    "pros": r"장점|좋았던|만족",
    "cons": r"단점|아쉬운|아쉬웠|불편",
    "recommend": r"추천",
}
ENGAGEMENT = re.compile(r"공감|댓글|이웃|구독|좋아요")
SUMMARY_WORDS = re.compile(r"총평|정리|마무리|결론|한줄평|한 줄 평")
GREETING = re.compile(r"안녕하세요|반갑습니다|안녕")
NUMBER = re.compile(r"\d+(?:[.,]\d+)*")
# 글 끝의 "#인계동맛집 #파스타" 같은 해시태그. 짧은 라벨이라 원문 문장이 아니다
HASHTAG = re.compile(r"(?<![\w&#])#([0-9A-Za-z가-힣_]{1,30})")
MAX_HASHTAGS = 30


class TitleFeatures(BaseModel):
    length: int
    keyword_position: Literal["start", "middle", "end", "none"]
    has_number: bool
    has_brackets: bool
    has_question: bool
    has_exclamation: bool
    nouns: list[str]


class KeywordFeatures(BaseModel):
    full_match_count: int
    token_counts: dict[str, int]
    first_position_ratio: float | None
    in_first_paragraph: bool
    per_1000_chars: float


class PhotoFeatures(BaseModel):
    count: int
    intro_images: int  # 첫 문단 전에 나온 사진 수
    groups: int  # 연속된 사진 묶음 수
    max_group_size: int
    avg_paragraphs_between_groups: float | None
    per_1000_chars: float
    first_position_ratio: float | None  # 블록 순서 기준 0~1
    last_position_ratio: float | None


class SectionFeatures(BaseModel):
    count: int
    avg_chars: float


class EndingFeatures(BaseModel):
    has_summary: bool
    has_recommendation: bool
    asks_engagement: bool


class DocumentFeatures(BaseModel):
    version: str = FEATURES_VERSION
    char_count: int
    char_count_no_spaces: int
    sentence_count: int
    paragraph_count: int
    heading_count: int
    image_count: int
    list_count: int
    quote_count: int
    table_count: int
    number_count: int
    question_count: int
    title: TitleFeatures | None
    keyword: KeywordFeatures
    layout: str
    photos: PhotoFeatures
    sections: SectionFeatures
    top_nouns: list[tuple[str, int]]
    heading_nouns: list[str]
    slots: dict[str, bool]
    intro_type: Literal["greeting", "question", "summary", "story", "none"]
    ending: EndingFeatures
    # features-1에 나중에 더한 항목: 예전에 뽑은 특징에는 없으므로 기본값을 둔다
    hashtags: list[str] = []
    # 말투(나중에 더한 항목). 예전에 뽑은 특징에는 없다(None) → 다시 읽으면 생긴다
    style: StyleFeatures | None = None


def extract_features(document: ParsedDocument, keyword: str) -> DocumentFeatures:
    blocks = document.blocks
    text = document.plain_text
    body_chars = len(text.replace("\n", ""))
    paragraphs = [b.text for b in blocks if b.type == "paragraph"]
    all_sentences = [s for block in blocks if block.text for s in sentences(block.text)]
    keyword = " ".join(keyword.split())

    return DocumentFeatures(
        char_count=body_chars,
        char_count_no_spaces=len(re.sub(r"\s", "", text)),
        sentence_count=len(all_sentences),
        paragraph_count=len(paragraphs),
        heading_count=document.count("heading"),
        image_count=document.count("image"),
        list_count=document.count("list"),
        quote_count=document.count("quote"),
        table_count=document.count("table"),
        number_count=len(NUMBER.findall(text)),
        question_count=sum(1 for s in all_sentences if s.rstrip().endswith("?")),
        title=_title(document.title, keyword),
        keyword=_keyword(text, paragraphs, keyword, body_chars),
        layout="".join(LAYOUT_CODES[b.type] for b in blocks),
        photos=_photos(blocks, body_chars),
        sections=_sections(blocks),
        top_nouns=Counter(nouns(text)).most_common(30),
        heading_nouns=list(dict.fromkeys(n for b in blocks if b.type == "heading" for n in nouns(b.text))),
        slots={slot: bool(re.search(pattern, text)) for slot, pattern in SLOT_PATTERNS.items()},
        intro_type=_intro_type(paragraphs, keyword),
        ending=_ending(blocks),
        hashtags=extract_hashtags(text),
        style=extract_style(text, paragraphs),
    )


def extract_hashtags(text: str) -> list[str]:
    """본문의 #태그를 순서대로 중복 없이. 숫자만 있는 것(#1 같은 번호)은 태그로 보지 않는다."""
    tags = (m.group(1) for m in HASHTAG.finditer(text))
    return list(dict.fromkeys(tag for tag in tags if not tag.isdigit()))[:MAX_HASHTAGS]


def _title(title: str | None, keyword: str) -> TitleFeatures | None:
    if not title:
        return None
    # "제목 - 사이트명" 꼬리는 제목 패턴에서 뺀다
    title = re.split(r"\s+[-|:]\s+(?=[^-|:]+$)", title)[0].strip()
    index = title.find(keyword) if keyword else -1
    if index < 0:
        position = "none"
    elif index <= 2:
        position = "start"
    elif index + len(keyword) >= len(title) - 2:
        position = "end"
    else:
        position = "middle"
    return TitleFeatures(
        length=len(title),
        keyword_position=position,
        has_number=bool(NUMBER.search(title)),
        has_brackets=bool(re.search(r"[\[\](){}【】「」]", title)),
        has_question="?" in title,
        has_exclamation="!" in title,
        nouns=list(dict.fromkeys(nouns(title))),
    )


def _keyword(text: str, paragraphs: list[str], keyword: str, body_chars: int) -> KeywordFeatures:
    tokens = keyword.split()
    full = text.count(keyword) if keyword else 0
    first = text.find(keyword) if keyword else -1
    return KeywordFeatures(
        full_match_count=full,
        token_counts={token: text.count(token) for token in tokens},
        first_position_ratio=round(first / len(text), 3) if first >= 0 and text else None,
        in_first_paragraph=bool(paragraphs) and any(token in paragraphs[0] for token in tokens),
        per_1000_chars=round(full * 1000 / body_chars, 2) if body_chars else 0.0,
    )


def _photos(blocks: list[Block], body_chars: int) -> PhotoFeatures:
    positions = [i for i, b in enumerate(blocks) if b.type == "image"]
    first_paragraph = next((i for i, b in enumerate(blocks) if b.type == "paragraph"), len(blocks))

    groups: list[tuple[int, int]] = []  # (시작, 끝) 블록 인덱스
    for index in positions:
        if groups and groups[-1][1] == index - 1:
            groups[-1] = (groups[-1][0], index)
        else:
            groups.append((index, index))

    gaps = [
        sum(1 for b in blocks[prev_end + 1 : start] if b.type == "paragraph")
        for (_, prev_end), (start, _) in zip(groups, groups[1:])
    ]
    last = max(len(blocks) - 1, 1)
    return PhotoFeatures(
        count=len(positions),
        intro_images=sum(1 for i in positions if i < first_paragraph),
        groups=len(groups),
        max_group_size=max((end - start + 1 for start, end in groups), default=0),
        avg_paragraphs_between_groups=round(sum(gaps) / len(gaps), 2) if gaps else None,
        per_1000_chars=round(len(positions) * 1000 / body_chars, 2) if body_chars else 0.0,
        first_position_ratio=round(positions[0] / last, 3) if positions else None,
        last_position_ratio=round(positions[-1] / last, 3) if positions else None,
    )


def _sections(blocks: list[Block]) -> SectionFeatures:
    sizes: list[int] = []
    current = 0
    for block in blocks:
        if block.type == "heading":
            if current:
                sizes.append(current)
            current = 0
        else:
            current += len(block.text)
    if current:
        sizes.append(current)
    return SectionFeatures(count=len(sizes), avg_chars=round(sum(sizes) / len(sizes), 1) if sizes else 0.0)


def _intro_type(paragraphs: list[str], keyword: str) -> str:
    if not paragraphs:
        return "none"
    first = paragraphs[0]
    if GREETING.search(first[:40]):
        return "greeting"
    if "?" in first:
        return "question"
    if any(token in first[:100] for token in keyword.split()) and re.search(r"추천|정리|후기|리뷰|소개", first[:100]):
        return "summary"
    return "story"


def _ending(blocks: list[Block]) -> EndingFeatures:
    tail = blocks[-4:]
    tail_text = " ".join(b.text for b in tail)
    headings = [b.text for b in blocks if b.type == "heading"]
    return EndingFeatures(
        has_summary=bool(SUMMARY_WORDS.search(tail_text)) or any(SUMMARY_WORDS.search(h) for h in headings[-2:]),
        has_recommendation="추천" in tail_text,
        asks_engagement=bool(ENGAGEMENT.search(tail_text)),
    )
