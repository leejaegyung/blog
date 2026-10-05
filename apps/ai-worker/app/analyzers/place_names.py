"""사용자가 적은 알려줄 내용에서 장소 이름일 만한 명사 묶음을 뽑는다(예: "카시오 도산점", "서울숲").
실제 장소인지는 카카오 로컬 검색으로 확인하고 사용자가 고른다.
"""

import re

from app.analyzers.nlp import kiwi

NOUN_TAGS = {"NNP", "NNG", "SL", "SH", "SN"}
# 이 말로 끝나면 장소일 가능성이 높다
PLACE_SUFFIX = re.compile(
    r"(점|숲|공원|역|카페|스토어|매장|시장|거리|타워|몰|센터|호텔|식당|당|관|원|궁|산|강|해변|해수욕장|섬|마을|빌딩|플라자|백화점|아울렛|"
    r"성당|교회|사찰|사|박물관|미술관|도서관|극장|경기장|구장|대학교|학교|병원|빵집|베이커리|브루어리|펍|바|정|각|루|집)$"
)
# 장소가 아닌 흔한 명사(단독으로는 찾지 않는다)
COMMON = {
    "커피", "시계", "가격", "메뉴", "경기", "중계", "시그니처", "프리미엄", "한국", "일본", "직접", "방문", "구경", "정도", "후",
    "시간", "사람", "주차", "웨이팅", "분위기", "맛", "음식", "음료", "디저트", "아이스티", "돗자리", "오늘", "하루", "내용", "정보",
}
MAX_CANDIDATES = 8


def place_candidates(texts: list[str]) -> list[str]:
    found: dict[str, int] = {}
    for text in texts:
        tokens = kiwi().tokenize(text)
        run: list = []

        def flush() -> None:
            # 앞뒤의 한 글자 일반 명사·숫자("때", "2")는 떼어 낸다
            while run and (run[0].tag == "SN" or (run[0].tag == "NNG" and len(run[0].form) == 1)):
                run.pop(0)
            while run and (run[-1].tag == "SN" or (run[-1].tag == "NNG" and len(run[-1].form) == 1)):
                run.pop()
            if not run:
                return
            phrase = text[run[0].start: run[-1].start + run[-1].len].strip()
            proper = any(t.tag == "NNP" for t in run)
            run.clear()
            words = phrase.split()
            if len(phrase) < 2 or phrase in COMMON or all(w in COMMON for w in words):
                return
            suffix = bool(PLACE_SUFFIX.search(phrase))
            # 고유 명사가 있거나 장소 접미사로 끝나야 장소 후보로 본다
            if not (proper or suffix):
                return
            score = (2 if suffix else 0) + (1 if len(words) > 1 else 0)
            found[phrase] = max(found.get(phrase, 0), score)

        for token in tokens:
            joined = run and text[run[-1].start + run[-1].len: token.start].strip() == ""
            if token.tag in NOUN_TAGS and token.form not in COMMON:
                if run and not joined:
                    flush()
                run.append(token)
            else:
                flush()
        flush()
    # 장소다운 것(접미사·여러 낱말) 먼저, 같은 점수면 나온 순서
    ordered = sorted(found, key=lambda p: -found[p])
    # 다른 후보에 통째로 들어가는 짧은 후보는 뺀다("도산점" ⊂ "카시오 도산점")
    result = [p for p in ordered if not any(p != q and p in q for q in ordered)]
    return result[:MAX_CANDIDATES]
