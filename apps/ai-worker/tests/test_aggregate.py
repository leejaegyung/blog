from app.analyzers.aggregate import aggregate
from app.analyzers.features import extract_features
from app.references.document import Block, ParsedDocument

P = lambda t: Block(type="paragraph", text=t)  # noqa: E731
H = lambda t: Block(type="heading", text=t, level=2)  # noqa: E731
I = Block(type="image")  # noqa: E741


def features(blocks, title="인계동 파스타 맛집 [내돈내산]", keyword="인계동 파스타"):
    return extract_features(ParsedDocument(source="text", title=title, extractor="text", blocks=blocks), keyword)


DOCS = [
    features([I, I, P("안녕하세요 인계동 파스타 다녀왔어요."), H("주차 정보"), P("주차 가능합니다."), I,
              H("메뉴 가격"), P("봉골레 19,000원"), I, I, H("총평"), P("추천해요!")]),
    features([I, P("인계동 파스타 후기입니다. 가격은 2만원."), H("주차"), P("주차는 어려워요."), I, I,
              H("메뉴"), P("크림 파스타가 맛있었어요.")]),
    features([P("오늘은 인계동에서 파스타를 먹었어요."), H("분위기"), P("조용한 분위기였어요."), I,
              H("메뉴"), P("라자냐를 먹었습니다.")], title="수원 데이트 코스"),
]


def test_spreads_and_photo_pattern() -> None:
    stats = aggregate(DOCS, "인계동 파스타")

    assert stats.reference_count == 3
    assert stats.heading_count.median == 2
    assert stats.photos.count.median == 3
    assert stats.photos.starts_with_photo_share == 0.667
    assert stats.photos.intro_images.p75 == 1.5


def test_slots_topics_and_titles() -> None:
    stats = aggregate(DOCS, "인계동 파스타")

    slots = {s.label: s.share for s in stats.slots}
    assert slots["parking"] == 0.667 and slots["menu"] == 1.0 and slots["phone"] == 0.0
    assert stats.slots[0].share >= stats.slots[-1].share
    topics = {t.term: t.documents for t in stats.topics}
    assert topics == {"메뉴": 3, "주차": 2}  # 한 글에만 나온 소제목 명사(분위기, 가격, 총평)는 빠진다
    assert "파스타" not in {t.term for t in stats.terms}  # 키워드 자체는 빼고 연관어만
    positions = {s.label: s.share for s in stats.title.keyword_position}
    assert positions == {"start": 0.667, "none": 0.333}
    assert stats.title.with_brackets_share == 0.667


def test_opening_patterns() -> None:
    stats = aggregate(DOCS, "인계동 파스타")

    assert {s.label: s.share for s in stats.opening_patterns} == {
        "사진→문단→소제목": 0.667,
        "문단→소제목→문단": 0.333,
    }


def test_single_reference_keeps_single_document_terms() -> None:
    stats = aggregate(DOCS[:1], "인계동 파스타")

    assert {"주차", "메뉴", "가격", "총평"} <= {t.term for t in stats.topics}


def test_no_references() -> None:
    assert aggregate([], "파스타") is None


def test_hashtag_frequency_and_count() -> None:
    tagged = [
        features([P("인계동 파스타 다녀왔어요."), P("#인계동맛집 #수원맛집 #파스타")]),
        features([P("인계동 파스타 후기."), P("#인계동맛집 #데이트")]),
        features([P("인계동 파스타 먹었어요.")]),
    ]
    stats = aggregate(tagged, "인계동 파스타")

    assert [(t.term, t.documents) for t in stats.hashtags] == [("인계동맛집", 2)]
    assert stats.hashtag_count.median == 2.5
    assert aggregate(DOCS, "인계동 파스타").hashtag_count is None
