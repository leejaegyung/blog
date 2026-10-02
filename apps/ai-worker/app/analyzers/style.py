"""글의 말투(문체)를 코드로 잰다. 원문 문장은 남기지 않고 비율·빈도·짧은 구어 낱말만 남긴다.

참고 글의 말투 분포를 초안에 주고, 초안이 AI처럼 들리는 상투어를 쓰면 게시 전 검사에서 알려 준다.
"""

import re
from statistics import mean, pstdev

from pydantic import BaseModel

from app.analyzers.nlp import sentences

EMOJI = re.compile("[\U0001F300-\U0001FAFF☀-➿⭐❤]")
LAUGH = re.compile(r"[ㅋㅎ]{2,}")
TILDE = re.compile(r"~")
ELLIPSIS = re.compile(r"\.{2,}|…")
FIRST_PERSON = re.compile(r"(?<![가-힣])(저는|제가|저도|저희|나는|내가|나도)(?![가-힣])")
TRAILING = re.compile(r"[\s.!?~ㅎㅋ^;:)(\-…" + "\U0001F300-\U0001FAFF☀-➿⭐❤" + r"]+$")

# 블로그에서 사람 말투를 만드는 구어 낱말(낱말 하나라 원문 문장이 아니다)
CASUAL_WORDS = (
    "진짜", "완전", "너무", "엄청", "살짝", "약간", "되게", "꽤", "은근", "역시", "아무래도", "솔직히", "사실",
    "넘", "짱", "대박", "강추", "존맛", "꿀팁", "찐", "최애", "인생", "핵", "그냥", "막", "일단", "근데", "암튼",
)
CASUAL = re.compile(r"(?<![가-힣])(" + "|".join(CASUAL_WORDS) + r")")

# AI가 쓴 티가 나는 상투 표현. 초안 프롬프트에서 피하게 하고, 게시 전 검사에서 찾아 알려 준다
AI_PHRASES = {
    "소개해 드리려고 합니다": r"소개해\s?드리(려고|고자|겠)",
    "알아보겠습니다": r"알아보(겠습니다|도록 하겠|아요\s?함께)",
    "~에 대해 이야기해 보겠": r"에 대해\s?(이야기|말씀|정리)해\s?보(겠|려)",
    "결론적으로": r"결론적으로",
    "마무리하며": r"마무리하며|마치며",
    "다양한": r"다양한",
    "특별한 경험": r"특별한\s?(경험|시간|추억)",
    "완벽한": r"완벽한",
    "만족스러운 시간": r"만족스러운\s?(시간|경험)",
    "~하는 것을 추천드립니다": r"것을\s?(추천|권해)\s?드립니다",
    "누구나 ~할 수 있": r"누구나\s?\S+\s?수 있",
    "~의 매력": r"의\s?매력(을|이|에)",
    "선사합니다": r"선사(합니다|해요|했)",
    "자리잡고 있": r"자리\s?잡고\s?있",
    "어우러져": r"어우러(져|진|지)",
    "한마디로": r"한마디로",
    "~라고 할 수 있습니다": r"라고\s?할 수 있(습니다|어요)",
    "그렇다면": r"^그렇다면",
}
AI_PATTERNS = {label: re.compile(pattern, re.MULTILINE) for label, pattern in AI_PHRASES.items()}


class StyleFeatures(BaseModel):
    sentence_chars: float  # 문장 평균 글자 수
    sentence_chars_cv: float  # 문장 길이 들쭉날쭉한 정도(표준편차/평균). 사람 글은 크고 AI 글은 고르다
    endings: dict[str, float]  # 문장 끝 비율: haeyo(~요) · hamnida(~니다) · plain(~다·~네) · eum(~음·~함) · other(명사로 끝·감탄)
    exclaim_ratio: float  # 느낌표로 끝나는 문장 비율
    question_ratio: float
    emoji_per_1000: float
    laugh_per_1000: float  # ㅋㅋ·ㅎㅎ
    tilde_per_1000: float  # ~
    ellipsis_per_1000: float  # ... …
    short_paragraph_share: float  # 40자 미만 문단 비율(한두 마디로 끊는 리듬)
    first_person_per_1000: float
    casual_words: list[str]  # 쓰인 구어 낱말
    ai_phrases: list[str]  # 쓰인 상투 표현 이름


def ending_class(sentence: str) -> str | None:
    stripped = TRAILING.sub("", sentence.strip())
    if len(stripped) < 2:
        return None
    if stripped.endswith(("요", "죠")):
        return "haeyo"
    if stripped.endswith(("니다", "니까")):
        return "hamnida"
    if stripped.endswith(("다", "네", "지", "군", "걸", "래")):
        return "plain"
    if stripped.endswith(("음", "함", "임", "듯", "됨", "봄", "옴", "짐")):
        return "eum"
    return "other"


def ai_phrases(text: str) -> list[str]:
    return [label for label, pattern in AI_PATTERNS.items() if pattern.search(text)]


def extract_style(text: str, paragraphs: list[str]) -> StyleFeatures | None:
    lines = [s.strip() for p in (paragraphs or [text]) for s in sentences(p) if s.strip()]
    if not lines:
        return None
    chars = max(1, len(text.replace("\n", "")))
    per_1000 = lambda pattern: round(len(pattern.findall(text)) / chars * 1000, 2)  # noqa: E731
    lengths = [len(s) for s in lines]
    classes = [c for s in lines if (c := ending_class(s))]
    shares = {label: round(classes.count(label) / len(classes), 3) for label in ("haeyo", "hamnida", "plain", "eum", "other")} if classes else {}
    return StyleFeatures(
        sentence_chars=round(mean(lengths), 1),
        sentence_chars_cv=round(pstdev(lengths) / mean(lengths), 3) if len(lengths) > 1 else 0.0,
        endings=shares,
        exclaim_ratio=round(sum(1 for s in lines if s.rstrip(" ~ㅎㅋ").endswith("!")) / len(lines), 3),
        question_ratio=round(sum(1 for s in lines if s.rstrip(" ~ㅎㅋ").endswith("?")) / len(lines), 3),
        emoji_per_1000=per_1000(EMOJI),
        laugh_per_1000=per_1000(LAUGH),
        tilde_per_1000=per_1000(TILDE),
        ellipsis_per_1000=per_1000(ELLIPSIS),
        short_paragraph_share=round(sum(1 for p in paragraphs if len(p) < 40) / len(paragraphs), 3) if paragraphs else 0.0,
        first_person_per_1000=per_1000(FIRST_PERSON),
        casual_words=sorted(set(CASUAL.findall(text))),
        ai_phrases=ai_phrases(text),
    )
