from app.analyzers.features import extract_features
from app.references.document import Block, ParsedDocument


def doc(blocks: list[Block], title: str | None = "수원 인계동 파스타 맛집 추천 [내돈내산]") -> ParsedDocument:
    return ParsedDocument(source="text", title=title, extractor="text", blocks=blocks)


P = lambda text: Block(type="paragraph", text=text)  # noqa: E731
H = lambda text: Block(type="heading", text=text, level=2)  # noqa: E731
I = Block(type="image")  # noqa: E741

REVIEW = doc([
    I, I,
    P("안녕하세요! 오늘은 수원 인계동 파스타 맛집을 다녀왔어요."),
    H("위치와 주차"),
    P("수원시 팔달구 인계동 123에 있어요. 주차는 건물 지하에 가능합니다. 문의는 031-123-4567로 하세요."),
    I,
    H("메뉴와 가격"),
    P("봉골레 파스타는 19,000원이고 런치 세트는 2만 원이에요. 양이 많을까요?"),
    I, I, I,
    P("봉골레가 정말 맛있었어요. 파스타 면도 적당히 익었어요."),
    P("아쉬운 점은 웨이팅이 조금 있었다는 거예요."),
    H("총평"),
    P("인계동 파스타 찾으신다면 추천해요. 공감과 댓글 부탁드려요!"),
])


def test_counts_and_layout() -> None:
    f = extract_features(REVIEW, "수원 인계동 파스타")

    assert f.layout == "IIPHPIHPIIIPPHP"
    assert (f.paragraph_count, f.heading_count, f.image_count) == (6, 3, 6)
    assert f.question_count == 1
    assert f.number_count >= 4


def test_photo_pattern() -> None:
    photos = extract_features(REVIEW, "파스타").photos

    assert photos.intro_images == 2
    assert (photos.groups, photos.max_group_size) == (3, 3)
    # 묶음 [0-1] [5] [8-10] 사이 문단 수: 2개(P,H,P), 1개(H,P) → 평균 1.5
    assert photos.avg_paragraphs_between_groups == 1.5
    assert photos.first_position_ratio == 0.0


def test_keyword_and_title() -> None:
    f = extract_features(REVIEW, "수원  인계동 파스타")

    assert f.keyword.full_match_count == 1
    assert f.keyword.token_counts == {"수원": 2, "인계동": 3, "파스타": 4}  # 제목은 본문 빈도에 넣지 않는다
    assert f.keyword.in_first_paragraph
    assert f.title.keyword_position == "start"
    assert f.title.has_brackets and not f.title.has_question
    assert "파스타" in f.title.nouns


def test_title_site_suffix_is_ignored() -> None:
    f = extract_features(doc([P("본문입니다.")], title="파스타 - 위키백과, 우리 모두의 백과사전"), "파스타")

    assert (f.title.length, f.title.keyword_position) == (3, "start")


def test_slots_intro_and_ending() -> None:
    f = extract_features(REVIEW, "수원 인계동 파스타")

    assert {k for k, v in f.slots.items() if v} == {
        "price", "address", "phone", "parking", "wait", "menu", "cons", "recommend",
    }
    assert f.intro_type == "greeting"
    assert f.ending.has_summary and f.ending.has_recommendation and f.ending.asks_engagement


def test_nouns_and_sections() -> None:
    f = extract_features(REVIEW, "파스타")

    top = dict(f.top_nouns)
    assert top["파스타"] >= 3 and "봉골레" in top
    assert "오늘" not in top  # 불용어
    assert f.heading_nouns[:3] == ["위치", "주차", "메뉴"]
    assert f.sections.count == 4


def test_output_contains_no_original_sentences() -> None:
    dumped = extract_features(REVIEW, "파스타").model_dump_json()

    assert "적당히 익었어요" not in dumped
    assert "031-123-4567" not in dumped


def test_empty_document_edges() -> None:
    f = extract_features(doc([H("제목만")], title=None), "파스타")

    assert f.title is None
    assert f.intro_type == "none"
    assert f.photos.count == 0 and f.photos.first_position_ratio is None
    assert f.keyword.first_position_ratio is None


def test_english_noise_is_filtered() -> None:
    from app.analyzers.nlp import nouns

    assert nouns("The pasta has 200 mg of salt and Pasta is from Italy") == ["pasta", "salt", "pasta", "italy"]


def test_hashtags_are_extracted_without_numbers_or_duplicates() -> None:
    f = extract_features(doc([P("잘 먹었어요. 1번 메뉴 추천!"), P("#인계동맛집 #수원파스타 #1 #인계동맛집 C#언어 #데이트_코스")]), "인계동 파스타")

    assert f.hashtags == ["인계동맛집", "수원파스타", "데이트_코스"]
