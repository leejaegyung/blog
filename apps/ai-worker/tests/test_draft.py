import json

from fastapi.testclient import TestClient

from app.generators.draft import Draft, DraftInput, check_draft, missing_facts, unsupported_specifics
from app.generators.writing_plan import FactInput
from app.llm.factory import get_router
from app.llm.router import LLMRouter
from app.llm.types import LLMRequest, LLMResult, Target
from app.main import app

FACTS = [
    FactInput(fact_key="장소명", fact_value="OO파스타"),
    FactInput(fact_key="가격", fact_value="런치 세트 19,000원"),
    FactInput(fact_key="좋았던 점", fact_value="봉골레가 가장 맛있었음"),
]
PLAN = {
    "outline": [{"heading": "메뉴", "purpose": "", "key_points": [], "fact_keys": ["가격"], "image_ids": [2, 3]}],
    "keywords": {"primary": ["인계동 파스타"], "secondary": []},
    "required_fact_keys": ["장소명", "가격", "좋았던 점"],
    "forbidden_claims": ["주차 가능 여부는 입력되지 않았으므로 단정하지 말 것"],
    "search_intent": "x",
    "title_candidates": ["무시됨"],
}
INPUT = DraftInput(keyword="인계동 파스타", tone="friendly", target_length=90, title="인계동 파스타 OO파스타 후기",
                   facts=FACTS, plan=PLAN, image_ids=[1, 2, 3])


def draft(**overrides) -> Draft:
    base = {
        "title": " 인계동 파스타 OO파스타 후기 ",
        "intro": [{"type": "image", "image_id": 1}, {"type": "paragraph", "text": "인계동 파스타 맛집 OO파스타에 다녀왔어요."}],
        "sections": [{"heading": "메뉴와 가격", "blocks": [
            {"type": "paragraph", "text": "런치 세트는 19,000원이에요. 봉골레가 제일 맛있었어요."},
            {"type": "image", "image_id": 2},
            {"type": "image", "image_id": 2},
            {"type": "image", "image_id": 99},
            {"type": "list", "items": ["봉골레", " ", "크림"]},
            {"type": "paragraph", "text": "   "},
        ]}],
        "closing": [{"type": "quote", "text": "인계동 파스타 찾으면 추천해요"}],
        "tags": ["#인계동 파스타", "인계동파스타", "수원맛집", " "],
    }
    return Draft.model_validate({**base, **overrides})


def test_flattens_blocks_and_cleans_images_lists_tags() -> None:
    checked = check_draft(draft(), INPUT)

    assert [b.type for b in checked.blocks] == ["image", "paragraph", "heading", "paragraph", "image", "list", "quote"]
    assert checked.blocks[5].items == ["봉골레", "크림"]
    assert checked.title == "인계동 파스타 OO파스타 후기"
    assert checked.tags == ["인계동파스타", "수원맛집"]
    assert checked.keyword_count == 2
    codes = [w.code for w in checked.warnings]
    assert codes.count("image_invalid") == 2  # 중복 2, 없는 99
    assert "image_unplaced" in codes  # 3번 사진 누락


def test_recommended_hashtags_come_first_and_are_merged() -> None:
    data = INPUT.model_copy(update={"hashtags": ["수원맛집", "#인계동 데이트", "인계동파스타"]})

    checked = check_draft(draft(), data)

    assert checked.tags == ["수원맛집", "인계동데이트", "인계동파스타"]


def test_text_rendering_and_length_warning() -> None:
    checked = check_draft(draft(), INPUT)

    assert checked.text.splitlines()[0] == "인계동 파스타 OO파스타 후기"
    assert "- 봉골레" in checked.text
    assert checked.char_count == len(checked.text.replace("\n", "")) - len("인계동 파스타 OO파스타 후기")
    assert 63 <= checked.char_count <= 126  # 목표 90자의 70~140%
    assert not any(w.code == "length" for w in checked.warnings)
    long_input = INPUT.model_copy(update={"target_length": 2500})
    assert any(w.code == "length" for w in check_draft(draft(), long_input).warnings)


def test_flags_specifics_not_in_facts() -> None:
    body = "런치 세트는 19,000원이고 디너는 2만 원이에요. 영업은 오전 11시부터, 문의 031-123-4567. 인계로 123에 있어요."

    messages = [w.message for w in unsupported_specifics(body, FACTS)]

    assert messages == [
        "입력하지 않은 가격 정보: 2만 원",
        "입력하지 않은 시간 정보: 오전 11시",
        "입력하지 않은 전화번호 정보: 031-123-4567",
        "입력하지 않은 주소 정보: 인계로 123",
    ]


def test_flags_numeric_fact_not_used() -> None:
    warnings = missing_facts("런치 세트가 괜찮았어요.", FACTS, ["가격", "장소명"])

    assert [w.message for w in warnings] == ["'가격' 값(런치 세트 19,000원)이 본문에 그대로 없습니다."]
    assert missing_facts("런치 세트 19,000 원", FACTS, ["가격"]) == []


class Recorder:
    provider = "anthropic"

    def __init__(self) -> None:
        self.requests: list[LLMRequest] = []

    async def generate(self, request: LLMRequest, model: str) -> LLMResult:
        self.requests.append(request)
        return LLMResult(provider="anthropic", model=model, text="{}", parsed=draft(),
                         input_tokens=3000, output_tokens=2500, stop_reason="end_turn")


def call(router: LLMRouter, body: dict) -> dict:
    app.dependency_overrides[get_router] = lambda: router
    try:
        response = TestClient(app).post("/posts/draft", json=body)
        return {"status": response.status_code, **response.json()}
    finally:
        app.dependency_overrides.clear()


def test_draft_endpoint_sends_plan_without_title_candidates() -> None:
    claude = Recorder()

    payload = {**INPUT.model_dump(), "photo_notes": {"1": {"type": "food", "description": "파스타로 보이는 음식", "usable": True}}}
    body = call(LLMRouter({"anthropic": claude}, [Target.parse("anthropic:claude-opus-5")]), payload)

    assert body["draft"]["blocks"][0] == {"type": "image", "text": None, "image_id": 1, "items": None}
    assert body["prompt_version"] == "blog-draft-v3"
    sent = json.loads(claude.requests[0].prompt)
    assert "title_candidates" not in sent["plan"]
    assert sent["tone"] == "친근한 말투"
    assert sent["photos"] == [
        {"id": 1, "order": 1, "type": "food", "description": "파스타로 보이는 음식"},
        {"id": 2, "order": 2},
        {"id": 3, "order": 3},
    ]
    assert claude.requests[0].max_tokens == 16000


def test_draft_endpoint_failure_and_missing_plan() -> None:
    body = call(LLMRouter({}, [Target.parse("anthropic:claude-opus-5")]), INPUT.model_dump())
    assert body["draft"] is None and "not_configured" in body["draft_error"]

    body = call(LLMRouter({}, []), {**INPUT.model_dump(), "plan": {"outline": []}})
    assert body["status"] == 422
