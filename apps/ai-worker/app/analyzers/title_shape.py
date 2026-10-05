"""제목의 "모양"만 뽑는다: 고유한 이름·내용은 자리표({키워드}·{명사}·{숫자}·{날짜})로 바꾸고,
괄호·구분 기호·블로그 제목에 흔한 낱말(후기·내돈내산·일상 …)과 말 끝(~한 날)만 남긴다. 원래 제목 글은 남기지 않는다.
"""

import re

from app.analyzers.nlp import kiwi

KEYWORD = "{키워드}"
NOUN = "{명사}"
NUMBER = "{숫자}"
DATE = "{날짜}"

# 블로그 제목에 자주 쓰는 틀 낱말(이건 자리표로 바꾸지 않고 남긴다)
TITLE_WORDS = {
    "후기", "리뷰", "추천", "솔직", "솔직후기", "내돈내산", "일상", "기록", "일기", "브이로그", "vlog", "데일리", "근황", "하루",
    "맛집", "카페", "데이트", "방문", "방문기", "정보", "총정리", "정리", "꿀팁", "팁", "위치", "메뉴", "메뉴판", "가격", "주차",
    "웨이팅", "영업시간", "예약", "여행", "코스", "주말", "평일", "혼밥", "신상", "오픈", "비교", "가성비", "분위기", "뷰",
    "첫", "재방문", "단골", "날", "오늘", "강추", "인생", "기념", "선물", "구경", "산책", "나들이",
}
NOUN_TAGS = {"NNG", "NNP", "NNB", "NR", "SL", "SH", "XR"}
KEEP_SYMBOLS = re.compile(r"^[\[\]()<>{}【】「」『』|,.·:;~!?\-–—/+&#*\s]+$")


DATE_PATTERN = re.compile(r"\d{2,4}\s*[./-]\s*\d{1,2}\s*[./-]\s*\d{1,2}\.?|\d{1,2}\s*월\s*\d{1,2}\s*일|\d{2,4}\s*년\s*\d{1,2}\s*월")


def _part(text: str) -> str:
    # 날짜는 먼저 자리표로(형태소 분석기가 숫자·기호로 쪼갠다)
    pieces = DATE_PATTERN.split(text)
    if len(pieces) > 1:
        return DATE.join(_words(p) for p in pieces)
    return _words(text)


def _words(text: str) -> str:
    if not text.strip():
        return text
    out: list[str] = []
    cursor = 0
    for token in kiwi().tokenize(text):
        start, end = token.start, token.start + token.len
        # 형태소가 겹치는 경우(다녀오+ㄴ → "다녀온") 이미 쓴 부분은 다시 쓰지 않는다
        if end <= cursor:
            continue
        start = max(start, cursor)
        out.append(text[cursor:start])
        surface = text[start:end]
        if token.tag == "SN":
            out.append(NUMBER)
        elif token.tag in NOUN_TAGS:
            out.append(surface if surface.lower() in TITLE_WORDS else NOUN)
        else:
            # 조사·어미·기호·흔한 동사는 그대로 둔다(제목의 말투와 틀)
            out.append(surface)
        cursor = end
    out.append(text[cursor:])
    return "".join(out)


def title_shape(title: str, keyword: str) -> str:
    keyword = " ".join(keyword.split())
    parts = title.split(keyword) if keyword else [title]
    shape = KEYWORD.join(_part(p) for p in parts)
    # 이어진 자리표·날짜 정리: "{명사} {명사}" → "{명사}", "{숫자}/{숫자}/{숫자}" → "{날짜}"
    shape = re.sub(r"\{숫자\}(?:\s*[./년월일-]\s*\{숫자\}){1,2}\s*[일.]?", DATE, shape)
    shape = re.sub(r"\{명사\}(?:\s*\{명사\})+", NOUN, shape)
    shape = re.sub(r"\{명사\}(?:의|과|와)?\s*\{명사\}", NOUN, shape)
    return re.sub(r"\s+", " ", shape).strip()


def title_words(title: str) -> list[str]:
    return sorted({t.form.lower() for t in kiwi().tokenize(title) if t.form.lower() in TITLE_WORDS})
