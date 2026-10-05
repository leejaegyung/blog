from fastapi.testclient import TestClient

from app.analyzers.aggregate import aggregate
from app.analyzers.features import extract_features
from app.analyzers.place_names import place_candidates
from app.analyzers.title_shape import title_shape, title_words
from app.main import app
from app.prompts import load_prompt
from app.references.document import Block, ParsedDocument


def test_title_shape_keeps_frame_and_hides_names() -> None:
    assert title_shape("[수원맛집] 인계동 파스타 OO파스타 솔직 후기", "인계동 파스타") == "[{명사}맛집] {키워드} {명사} 솔직 후기"
    assert title_shape("2026.10.04 일상 | 오늘 하루", "일상") == "{날짜} {키워드} | 오늘 하루"
    assert title_shape("10월 첫째 주 일상 기록 | 서울숲 산책하고 카페 다녀온 날", "일상").endswith("산책하고 카페 다녀온 날")
    assert "서울숲" not in title_shape("서울숲 산책하고 카페 다녀온 날", "일상")
    assert title_words("[오사카맛집] 돈카츠 내돈내산 후기") == ["내돈내산", "맛집", "후기"]


def test_reference_title_shapes_are_aggregated() -> None:
    def doc(title: str):
        return extract_features(ParsedDocument(source="text", title=title, extractor="text", blocks=[Block(type="paragraph", text="본문입니다. 오늘 다녀왔어요.")]), "일상")

    stats = aggregate([doc("9월 28일 일상, 성수 카페 다녀옴"), doc("10월 2일 일상, 망원 카페 다녀옴"), doc("일상 기록 | 서울숲")], "일상")

    assert stats is not None and stats.title is not None
    assert stats.title.shapes[0].label == "{날짜} {키워드}, {명사} 카페 다녀옴" and stats.title.shapes[0].share == 0.667
    assert {w.term for w in stats.title.words} >= {"일상", "카페"}


def test_plan_prompt_follows_reference_title_shapes_and_daily_mode() -> None:
    plan = load_prompt("writing-plan-v6")
    draft = load_prompt("blog-draft-v6")
    assert "shapes" in plan and "{키워드}" in plan
    assert "daily" in plan and "diary" in plan and "daily" in draft


def test_place_candidates_from_facts() -> None:
    found = place_candidates(["성수동 대림창고 갔다가 서울숲 산책, 저녁은 인계동 파스타인계에서", "커피 한 잔 마시고 시계 구경"])

    assert "성수동 대림창고" in found
    assert any("서울숲" in p for p in found)
    assert "커피" not in found and "시계" not in found

    response = TestClient(app).post("/places/candidates", json={"texts": ["서울숲에서 돗자리 펴고 F1 중계 봤다"]})
    assert response.status_code == 200
    assert any("서울숲" in p for p in response.json()["candidates"])
