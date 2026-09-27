import json

from fastapi.testclient import TestClient

from app.generators.writing_plan import PlanInput, WritingPlan, check_plan, missing_expected_facts
from app.llm.factory import get_router
from app.llm.router import LLMRouter
from app.llm.types import LLMRequest, LLMResult, Target
from app.main import app

INPUT = PlanInput(
    keyword="인계동 파스타",
    category="맛집",
    tone="natural",
    target_length=2500,
    facts=[
        {"fact_key": "장소명", "fact_value": "OO파스타"},
        {"fact_key": "가격", "fact_value": "런치 세트 19,000원"},
        {"fact_key": "좋았던 점", "fact_value": "봉골레가 가장 맛있었음"},
    ],
    images=[
        {"id": 11, "sort_order": 0, "vision": {"type": "exterior", "description": "가게 외관으로 보이는 사진", "usable": True}},
        {"id": 12, "sort_order": 1},
        {"id": 13, "sort_order": 2},
    ],
    analysis={"stats": {"slots": [
        {"label": "parking", "share": 0.8}, {"label": "price", "share": 0.9},
        {"label": "hours", "share": 0.3}, {"label": "reservation", "share": 0.6},
    ]}},
)

PLAN = WritingPlan(
    title_candidates=["인계동 파스타 OO파스타 후기", "인계동 파스타 OO파스타 후기", "제목 3", "제목 4", "제목 5", "제목 6"],
    search_intent="인계동에서 파스타 먹을 곳을 찾는다",
    outline=[
        {"heading": "OO파스타 첫인상", "purpose": "도입", "key_points": ["방문 이유"],
         "fact_keys": ["장소명"], "image_ids": [11, 12]},
        {"heading": "메뉴와 가격", "purpose": "가격 안내", "key_points": ["런치 세트"],
         "fact_keys": ["가격", "주차"], "image_ids": [12, 99]},
    ],
    keywords={"primary": ["인계동 파스타"], "secondary": ["수원 파스타"]},
    required_fact_keys=["장소명", "가격", "영업시간"],
    forbidden_claims=["주차 가능 여부를 단정하지 말 것", "  "],
)


def test_check_plan_enforces_facts_and_photos() -> None:
    checked = check_plan(PLAN, INPUT)

    assert checked.outline[1].fact_keys == ["가격"]  # 입력에 없는 "주차" 제거
    assert checked.outline[0].image_ids == [11, 12]
    assert checked.outline[1].image_ids == []  # 12는 중복, 99는 없는 사진
    assert checked.unplaced_image_ids == [13]
    assert checked.unused_fact_keys == ["좋았던 점"]
    assert checked.required_fact_keys == ["장소명", "가격", "좋았던 점"]  # 영업시간 제거, 누락 사실 추가
    assert checked.title_candidates == ["인계동 파스타 OO파스타 후기", "제목 3", "제목 4", "제목 5", "제목 6"]
    assert len(checked.corrections) == 4


def test_missing_expected_facts_become_forbidden_claims() -> None:
    claims = missing_expected_facts(INPUT)

    # 가격은 입력됨, 영업시간은 30%라 기대 정보 아님 → 주차·예약만
    assert claims == ["주차 가능 여부는 입력되지 않았으므로 단정하지 말 것", "예약 필요 여부는 입력되지 않았으므로 단정하지 말 것"]
    checked = check_plan(PLAN, INPUT)
    assert checked.forbidden_claims[:2] == claims
    assert "주차 가능 여부를 단정하지 말 것" in checked.forbidden_claims
    assert "" not in checked.forbidden_claims


def test_no_analysis_means_no_code_claims() -> None:
    assert missing_expected_facts(INPUT.model_copy(update={"analysis": None})) == []


class Recorder:
    provider = "anthropic"

    def __init__(self) -> None:
        self.requests: list[LLMRequest] = []

    async def generate(self, request: LLMRequest, model: str) -> LLMResult:
        self.requests.append(request)
        return LLMResult(provider="anthropic", model=model, text="{}", parsed=PLAN,
                         input_tokens=1500, output_tokens=900, stop_reason="end_turn")


def post(router: LLMRouter, body: dict) -> dict:
    app.dependency_overrides[get_router] = lambda: router
    try:
        response = TestClient(app).post("/posts/plan", json=body)
        return {"status": response.status_code, **response.json()}
    finally:
        app.dependency_overrides.clear()


def test_plan_endpoint_returns_checked_plan() -> None:
    claude = Recorder()

    body = post(LLMRouter({"anthropic": claude}, [Target.parse("anthropic:claude-opus-5")]), INPUT.model_dump())

    assert body["plan"]["unplaced_image_ids"] == [13]
    assert body["prompt_version"] == "writing-plan-v2"
    sent = json.loads(claude.requests[0].prompt)
    assert sent["tone"] == "자연스러운 후기"
    assert [p["id"] for p in sent["photos"]] == [11, 12, 13]
    assert sent["photos"][0]["vision"]["type"] == "exterior"
    assert sent["photos"][1]["vision"] is None
    assert claude.requests[0].output_model is WritingPlan


def test_plan_endpoint_reports_llm_failure() -> None:
    body = post(LLMRouter({}, [Target.parse("anthropic:claude-opus-5")]), INPUT.model_dump())

    assert body["plan"] is None
    assert "not_configured" in body["plan_error"]
    assert body["generations"][0]["status"] == "failed"


def test_plan_requires_facts() -> None:
    body = post(LLMRouter({}, []), {**INPUT.model_dump(), "facts": []})

    assert body["status"] == 422
