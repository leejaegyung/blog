"""게시 전 품질 검사 (기획서 7.1, 19장). 코드로만 판정한다 — 빠르고 결정적이며 비용이 없다.

점수는 서비스 내부 지표이며 네이버 순위나 노출을 보장하지 않는다.
"""

import hashlib
import json
import re
from collections import Counter
from typing import Literal

from pydantic import BaseModel

from app.analyzers.nlp import nouns, sentences
from app.generators.draft import SPECIFIC_PATTERNS, ContentBlock, _compact
from app.generators.writing_plan import SLOT_FACT_HINTS, FactInput

GATE_VERSION = "quality-1"

Severity = Literal["error", "warning", "info"]

EXAGGERATIONS = ["최고", "무조건", "완벽", "100%", "역대급", "인생 맛집", "인생맛집", "절대", "세상에서 제일", "대박"]
SPONSOR_FACT = re.compile(r"협찬|제공|광고|체험단|원고료|지원")
SPONSOR_DISCLOSURE = re.compile(r"협찬|제공받|광고|체험단|원고료|지원받")
SELF_PAID = re.compile(r"내돈내산|내 돈 내 산|직접 결제|자비로")
PERSONAL_PHONE = re.compile(r"01[016789]-?\d{3,4}-?\d{4}")
MAX_PARAGRAPH_SENTENCES = 4
KEYWORD_DENSITY_LIMIT = 8.0  # 1000자당. 참고자료 통계가 있으면 그 p75의 2배와 비교한다
DENSITY_MIN_CHARS = 500  # 이보다 짧은 글은 몇 번만 나와도 밀도가 튀어 판정하지 않는다


class ImageState(BaseModel):
    id: int
    usable: bool = True
    privacy_flags: list[str] = []


class QualityInput(BaseModel):
    keyword: str
    title: str
    blocks: list[ContentBlock]
    tags: list[str] = []
    facts: list[FactInput] = []
    plan: dict | None = None
    images: list[ImageState] = []
    target_length: int = 2500
    keyword_density_p75: float | None = None


class Issue(BaseModel):
    code: str
    severity: Severity
    message: str
    block_index: int | None = None
    excerpt: str | None = None  # 편집기에서 위치를 찾는 데 쓰는 본문 조각


class ScorePart(BaseModel):
    key: str
    label: str
    score: float
    max: float


class QualityReport(BaseModel):
    version: str = GATE_VERSION
    content_hash: str
    score: float
    parts: list[ScorePart]
    issues: list[Issue]
    metrics: dict[str, float]


def content_hash(title: str, blocks: list[ContentBlock]) -> str:
    raw = json.dumps([title, [b.model_dump() for b in blocks]], ensure_ascii=False, sort_keys=True)
    return hashlib.sha256(raw.encode()).hexdigest()


def check(data: QualityInput) -> QualityReport:
    keyword = " ".join(data.keyword.split())
    blocks = data.blocks
    texts = [(i, b.text or "\n".join(b.items or [])) for i, b in enumerate(blocks) if b.type != "image"]
    body = "\n".join(t for _, t in texts)
    compact_body = _compact(body)
    char_count = len(body.replace("\n", ""))
    paragraphs = [(i, b.text or "") for i, b in enumerate(blocks) if b.type == "paragraph"]
    issues: list[Issue] = []

    def locate(fragment: str) -> tuple[int | None, str | None]:
        for index, text in texts:
            if fragment in text:
                return index, fragment
        return None, None

    # 1. 사용자 사실 반영
    reflected = 0
    for fact in data.facts:
        if _fact_reflected(fact, compact_body, body):
            reflected += 1
        else:
            issues.append(Issue(code="fact_missing", severity="warning",
                                message=f"입력한 '{fact.fact_key}'({fact.fact_value})이 본문에 보이지 않습니다."))

    # 2. 입력에 없는 구체 정보 (Hallucination Gate)
    source = _compact(" ".join(f.fact_value for f in data.facts))
    for label, pattern in SPECIFIC_PATTERNS.items():
        for match in dict.fromkeys(m.group(0) for m in re.finditer(pattern, body)):
            if _compact(match) not in source:
                index, excerpt = locate(match)
                issues.append(Issue(code="unsupported_specific", severity="error",
                                    message=f"입력하지 않은 {label} 정보가 있습니다: {match}", block_index=index, excerpt=excerpt))

    # 3. 계획의 단정 금지 항목을 본문에서 언급
    forbidden_text = " ".join((data.plan or {}).get("forbidden_claims", []))
    provided = " ".join(f"{f.fact_key} {f.fact_value}" for f in data.facts)
    for label, hint_words in SLOT_FACT_HINTS.values():
        words = [w for w in hint_words if len(w) > 1]  # "원" 같은 한 글자는 어디에나 있다
        if not any(w in forbidden_text for w in words) or any(w in provided for w in words):
            continue
        for index, text in texts:
            hit = next((s for s in sentences(text) if any(w in s for w in words)), None)
            if hit:
                issues.append(Issue(code="forbidden_claim", severity="warning",
                                    message=f"입력하지 않은 {label}를 언급합니다. 사실인지 확인하세요.", block_index=index, excerpt=hit.strip()))
                break

    # 4. 광고·협찬 표기
    sponsored = bool(SPONSOR_FACT.search(provided))
    if sponsored and not SPONSOR_DISCLOSURE.search(body):
        issues.append(Issue(code="ad_disclosure", severity="error",
                            message="협찬·제공 받은 글이면 본문에 광고·협찬 사실을 밝혀야 합니다."))
    if not sponsored and (m := SELF_PAID.search(body + " " + data.title)) and not SELF_PAID.search(provided):
        index, excerpt = locate(m.group(0))
        issues.append(Issue(code="self_paid_claim", severity="warning",
                            message=f"'{m.group(0)}'은 입력한 사실이 아닙니다. 사실이면 사실 정보에 적어 주세요.",
                            block_index=index, excerpt=excerpt))

    # 5. 과장 표현
    for word in EXAGGERATIONS:
        if word in body:
            index, excerpt = locate(word)
            issues.append(Issue(code="exaggeration", severity="warning",
                                message=f"과장으로 보일 수 있는 표현: '{word}'", block_index=index, excerpt=excerpt))

    # 6. 개인 휴대전화 번호
    if m := PERSONAL_PHONE.search(body):
        index, excerpt = locate(m.group(0))
        issues.append(Issue(code="personal_info", severity="error",
                            message="개인 휴대전화 번호로 보이는 정보가 있습니다.", block_index=index, excerpt=excerpt))

    # 7. 키워드
    keyword_count = body.count(keyword) if keyword else 0
    density = keyword_count * 1000 / char_count if char_count else 0.0
    limit = max(KEYWORD_DENSITY_LIMIT, 2 * data.keyword_density_p75) if data.keyword_density_p75 else KEYWORD_DENSITY_LIMIT
    if keyword and keyword_count == 0:
        issues.append(Issue(code="keyword_missing", severity="warning", message=f"본문에 키워드 '{keyword}'가 없습니다."))
    elif char_count >= DENSITY_MIN_CHARS and density > limit:
        issues.append(Issue(code="keyword_stuffing", severity="warning",
                            message=f"키워드가 1000자당 {density:.1f}회로 많습니다(기준 {limit:.1f}회). 반복을 줄이세요."))
    title_has_keyword = bool(keyword) and all(token in data.title for token in keyword.split())
    if not title_has_keyword:
        issues.append(Issue(code="title_keyword", severity="warning", message="제목에 키워드가 없습니다."))
    if len(data.title) > 40:
        issues.append(Issue(code="title_length", severity="info", message=f"제목이 {len(data.title)}자로 깁니다."))

    # 8. 반복 문장
    all_sentences = [(i, s.strip()) for i, t in texts for s in sentences(t) if len(s.strip()) >= 8]
    counts = Counter(re.sub(r"\s+", " ", s) for _, s in all_sentences)
    for sentence, count in counts.items():
        if count > 1:
            index, excerpt = locate(sentence)
            issues.append(Issue(code="repeated_sentence", severity="warning",
                                message=f"같은 문장이 {count}번 나옵니다.", block_index=index, excerpt=excerpt))
    endings = Counter(_ending(s) for _, s in all_sentences if _ending(s))
    if len(all_sentences) >= 10 and endings and endings.most_common(1)[0][1] / len(all_sentences) > 0.6:
        ending, _ = endings.most_common(1)[0]
        issues.append(Issue(code="monotone_endings", severity="info",
                            message=f"문장 끝이 '{ending}'로 많이 반복됩니다. 어미를 섞으면 자연스럽습니다."))

    # 9. 가독성
    long_paragraphs = [(i, t) for i, t in paragraphs if len(sentences(t)) > MAX_PARAGRAPH_SENTENCES]
    for index, text in long_paragraphs[:3]:
        issues.append(Issue(code="long_paragraph", severity="info",
                            message="문단이 깁니다. 모바일에서 읽기 쉽게 나눠 보세요.", block_index=index, excerpt=text[:30]))

    # 10. 길이
    if not 0.7 * data.target_length <= char_count <= 1.4 * data.target_length:
        issues.append(Issue(code="length", severity="info", message=f"글자 수 {char_count}자가 목표 {data.target_length}자와 차이가 큽니다."))

    # 11. 사진
    placed = [b.image_id for b in blocks if b.type == "image"]
    by_id = {image.id: image for image in data.images}
    for image_id in placed:
        image = by_id.get(image_id)
        if image and image.privacy_flags:
            issues.append(Issue(code="privacy_photo", severity="error",
                                message=f"개인정보({', '.join(image.privacy_flags)})가 보이는 사진을 쓰고 있습니다.",
                                block_index=next(i for i, b in enumerate(blocks) if b.image_id == image_id)))
    if not data.images:
        issues.append(Issue(code="no_photos", severity="warning", message="올린 사진이 없습니다. 직접 찍은 사진이 글의 핵심 근거입니다."))
    elif not placed:
        issues.append(Issue(code="no_photos", severity="warning", message="본문에 사진이 없습니다."))
    unused = [image.id for image in data.images if image.id not in placed and image.usable and not image.privacy_flags]
    if unused and placed:
        issues.append(Issue(code="unused_photos", severity="info", message=f"쓰지 않은 사진이 {len(unused)}장 있습니다."))

    # 점수 (기획서 19장)
    headings = [i for i, b in enumerate(blocks) if b.type == "heading"]
    usable_ids = [image.id for image in data.images if image.usable and not image.privacy_flags]
    title_nouns = [n for n in dict.fromkeys(nouns(data.title)) if n not in keyword.split()]
    risky = sum(1 for i in issues if i.code in {"unsupported_specific", "forbidden_claim", "exaggeration",
                                                "repeated_sentence", "personal_info", "self_paid_claim", "ad_disclosure"})
    outline_nouns = [n for s in (data.plan or {}).get("outline", []) for n in nouns(s.get("heading", ""))]
    parts = [
        ScorePart(key="intent", label="검색 의도 충족", max=20, score=20 * _share(outline_nouns, body) if outline_nouns
                  else (20 if keyword_count else 0)),
        ScorePart(key="facts", label="사용자 사실 반영", max=20, score=20 * reflected / len(data.facts) if data.facts else 20),
        ScorePart(key="structure", label="구조 완성도", max=15, score=(5 if len(headings) >= 2 else 0)
                  + (5 if headings and any(b.type == "paragraph" for b in blocks[: headings[0]]) else 0)
                  + (5 if headings and any(b.type == "paragraph" for b in blocks[headings[-1] + 1:]) else 0)),
        ScorePart(key="photos", label="사진 활용", max=15, score=0 if any(i.code == "privacy_photo" for i in issues)
                  else 15 * (sum(1 for i in usable_ids if i in placed) / len(usable_ids) if usable_ids else (1 if placed else 0))),
        ScorePart(key="readability", label="가독성", max=10,
                  score=10 * (1 - len(long_paragraphs) / len(paragraphs)) if paragraphs else 0),
        ScorePart(key="title", label="제목·본문 일치", max=10,
                  score=(5 if title_has_keyword else 0) + 5 * (_share(title_nouns, body) if title_nouns else 1)),
        ScorePart(key="risk", label="중복·위험 표현", max=10, score=max(0.0, 10 - 2.5 * risky)),
    ]
    parts = [p.model_copy(update={"score": round(p.score, 1)}) for p in parts]

    order = {"error": 0, "warning": 1, "info": 2}
    return QualityReport(
        content_hash=content_hash(data.title, blocks),
        score=round(sum(p.score for p in parts), 1),
        parts=parts,
        issues=sorted(issues, key=lambda i: order[i.severity]),
        metrics={
            "char_count": char_count,
            "keyword_count": keyword_count,
            "keyword_per_1000_chars": round(density, 2),
            "paragraph_count": len(paragraphs),
            "heading_count": len(headings),
            "image_count": len(placed),
            "facts_reflected": reflected,
            "facts_total": len(data.facts),
        },
    )


def _fact_reflected(fact: FactInput, compact_body: str, body: str) -> bool:
    numbers = re.findall(r"\d[\d,.]*", fact.fact_value)
    if numbers:
        return all(_compact(n) in compact_body for n in numbers)
    key_nouns = list(dict.fromkeys(nouns(fact.fact_value)))
    if not key_nouns:
        return _compact(fact.fact_value) in compact_body
    # 문장형 사실은 핵심 명사 절반 이상이 본문에 있으면 반영된 것으로 본다
    return _share(key_nouns, body) >= 0.5


def _share(terms: list[str], body: str) -> float:
    unique = list(dict.fromkeys(terms))
    return sum(1 for t in unique if t in body) / len(unique) if unique else 0.0


def _ending(sentence: str) -> str:
    stripped = re.sub(r"[.!?~ㅎㅋ^]+$", "", sentence.strip())
    return stripped[-2:] if len(stripped) >= 2 else ""
