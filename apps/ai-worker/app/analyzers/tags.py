"""해시태그 거르기: 검색에 도움이 안 되는 태그와 비슷한 태그 반복을 뺀다.

조회수에 도움이 되는 태그는 사람들이 실제로 검색창에 치는 말(장소+주제, 구체적인 대상)이다.
이웃 늘리기용 태그(서이추·소통), 날짜, 기계적으로 붙인 변형(○○일상 여러 개)은 노출에 도움이 안 되고 글을 스팸처럼 보이게 한다.
"""

import re

# 검색 노출과 무관한 이웃·소통용 태그
COMMUNITY_TAGS = {
    "서이추", "서로이웃", "서로이웃추가", "이웃추가", "이웃환영", "소통", "소통해요", "맞팔", "선팔", "맞팔해요", "선팔하면맞팔",
    "팔로우", "좋아요", "공감", "댓글", "블로그", "블로거", "포스팅", "오늘", "일상스타그램", "데일리그램", "f4f", "l4l",
}
# 키워드를 나눌 때 버리는 말
STOPWORDS = {"및", "그리고", "와", "과", "의", "등", "또", "랑", "이랑", "하고", "에서", "후"}
# 날짜·숫자만 다른 태그(261009일상, 10월9일)
DATE_LIKE = re.compile(r"\d{4,}|\d{1,2}월|\d{1,2}일|\d+/\d+")
# 같은 끝말로 만든 변형은 이만큼만(남대문일상·시장일상·그릇일상… 반복 방지)
MAX_SAME_SUFFIX = 2
SUFFIX_WORDS = ("일상", "일기", "기록", "데일리", "맛집", "카페", "여행", "데이트", "추천", "후기", "리뷰", "나들이", "쇼핑", "브이로그")


def keyword_tokens(keyword: str) -> list[str]:
    """키워드에서 날짜·숫자·접속어를 뺀 낱말(예: "26/10/09 남대문 그릇 시장 및 일상" → 남대문·그릇·시장·일상)"""
    return [t for t in re.split(r"[\s,/·]+", keyword) if t and t not in STOPWORDS and not re.search(r"\d", t)]


def is_useful(tag: str) -> bool:
    lowered = tag.lower()
    return not (lowered in COMMUNITY_TAGS or DATE_LIKE.search(tag) or len(tag) < 2 or any(lowered.endswith(s) for s in ("및일상",)))


def limit_variants(tags: list[str]) -> list[str]:
    """같은 끝말(○○일상·○○맛집…)로 만든 태그는 앞에서부터 MAX_SAME_SUFFIX개만, 끝말 그 자체(일상)는 따로 둔다"""
    counts: dict[str, int] = {}
    result = []
    for tag in tags:
        suffix = next((s for s in SUFFIX_WORDS if tag.endswith(s) and tag != s), None)
        if suffix:
            counts[suffix] = counts.get(suffix, 0) + 1
            if counts[suffix] > MAX_SAME_SUFFIX:
                continue
        result.append(tag)
    return result


def clean_tags(tags: list[str]) -> list[str]:
    return limit_variants([t for t in tags if is_useful(t)])
