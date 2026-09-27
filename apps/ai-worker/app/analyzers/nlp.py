from functools import lru_cache

from kiwipiepy import Kiwi

NOUN_TAGS = {"NNG", "NNP", "SL"}
# 블로그 글에서 흔하지만 주제와 무관한 명사
STOPWORDS = {
    "오늘", "이번", "정말", "진짜", "생각", "느낌", "부분", "정도", "하나", "다음", "사진", "포스팅", "블로그",
    "여기", "저희", "우리", "때문", "경우", "사람", "이용", "사용", "시간", "추천", "후기", "리뷰", "안녕",
}
# 영문(SL)은 3글자 이상만 남기고, 관사·전치사·단위는 뺀다.
ENGLISH_STOPWORDS = {
    "the", "and", "for", "with", "from", "this", "that", "are", "was", "were", "you", "your", "not", "but",
    "all", "has", "have", "had", "its", "our", "can", "will", "one", "also", "more", "per", "into", "than",
    "kcal", "mg", "kg", "ml", "cm", "mm", "km",
}


@lru_cache
def kiwi() -> Kiwi:
    return Kiwi()


def nouns(text: str) -> list[str]:
    result = []
    for token in kiwi().tokenize(text):
        if token.tag not in NOUN_TAGS:
            continue
        if token.tag == "SL":
            form = token.form.lower()
            if len(form) >= 3 and form not in ENGLISH_STOPWORDS:
                result.append(form)
        elif len(token.form) >= 2 and token.form not in STOPWORDS:
            result.append(token.form)
    return result


def sentences(text: str) -> list[str]:
    return [sentence.text for sentence in kiwi().split_into_sents(text)]
