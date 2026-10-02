from app.analyzers.aggregate import aggregate
from app.analyzers.features import extract_features
from app.analyzers.style import ai_phrases, ending_class, extract_style
from app.references.document import Block, ParsedDocument

HUMAN = [
    "인계동 파스타 먹으러 다녀왔어요ㅎㅎ",
    "웨이팅 진짜 길었음…",
    "근데 봉골레 한 입 먹자마자 기다린 게 하나도 안 아깝더라구요!",
    "면이 완전 탱글탱글~",
    "다음엔 크림도 먹어 볼래요 😋",
]
ROBOT = [
    "오늘은 인계동 파스타 맛집을 소개해 드리려고 합니다.",
    "다양한 메뉴가 준비되어 있어 특별한 경험을 선사합니다.",
    "결론적으로 누구나 만족할 수 있는 곳이라고 할 수 있습니다.",
]


def test_sentence_endings_are_classified() -> None:
    assert ending_class("다녀왔어요ㅎㅎ") == "haeyo"
    assert ending_class("소개해 드리겠습니다.") == "hamnida"
    assert ending_class("맛있었다!") == "plain"
    assert ending_class("웨이팅 길었음…") == "eum"
    assert ending_class("면이 완전 탱글탱글~") == "other"
    assert ending_class("ㅋㅋ") is None


def test_style_measures_rhythm_without_keeping_sentences() -> None:
    style = extract_style("\n".join(HUMAN), HUMAN)

    assert style is not None
    assert style.endings["haeyo"] == 0.6 and style.endings["eum"] == 0.2
    assert 0 < style.exclaim_ratio < 0.3
    assert style.laugh_per_1000 > 0 and style.tilde_per_1000 > 0 and style.emoji_per_1000 > 0 and style.ellipsis_per_1000 > 0
    assert style.casual_words == ["근데", "완전", "진짜"]
    assert style.sentence_chars_cv > 0.3
    assert style.ai_phrases == []
    # 원문 문장은 들어 있지 않다
    assert "봉골레" not in style.model_dump_json()


def test_ai_stock_phrases_are_found() -> None:
    assert set(ai_phrases("\n".join(ROBOT))) >= {"소개해 드리려고 합니다", "다양한", "특별한 경험", "선사합니다", "결론적으로", "누구나 ~할 수 있", "~라고 할 수 있습니다"}


def test_voice_is_aggregated_from_references() -> None:
    def doc(lines: list[str]):
        return extract_features(ParsedDocument(source="text", extractor="text", blocks=[Block(type="paragraph", text=t) for t in lines]), "인계동 파스타")

    stats = aggregate([doc(HUMAN), doc(HUMAN[:3] + ["정말 맛있었어요."])], "인계동 파스타")

    assert stats is not None and stats.voice is not None
    assert stats.voice.reference_count == 2
    assert stats.voice.main_ending == "haeyo"
    assert {t.term for t in stats.voice.casual_words} >= {"진짜", "근데"}
