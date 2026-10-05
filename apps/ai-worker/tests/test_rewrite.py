import json

from fastapi.testclient import TestClient

from app.generators.rewrite import RewriteInput, RewriteOutput, check_rewrite
from app.generators.writing_plan import FactInput
from app.llm.factory import get_router
from app.llm.router import LLMRouter
from app.llm.types import LLMRequest, LLMResult, Target
from app.main import app

INPUT = RewriteInput(
    text="런치 세트는 19,000원이었어요. 봉골레가 맛있었어요.",
    instruction="longer",
    tone="friendly",
    before="인계동 파스타집에 다녀왔어요.",
    facts=[FactInput(fact_key="장소명", fact_value="OO파스타")],
    forbidden_claims=["주차 가능 여부는 입력되지 않았으므로 단정하지 말 것"],
)


def test_new_specifics_are_flagged_but_original_ones_are_not() -> None:
    warnings = check_rewrite("런치 세트는 19,000원이고 디너는 25,000원이에요. 오후 3시에 갔어요.", INPUT)

    assert [w.message for w in warnings] == ["입력하지 않은 가격 정보: 25,000원", "입력하지 않은 시간 정보: 오후 3시"]


class Recorder:
    provider = "anthropic"

    def __init__(self, text: str) -> None:
        self.text = text
        self.requests: list[LLMRequest] = []

    async def generate(self, request: LLMRequest, model: str) -> LLMResult:
        self.requests.append(request)
        return LLMResult(provider="anthropic", model=model, text="{}", parsed=RewriteOutput(text=self.text),
                         input_tokens=500, output_tokens=200, stop_reason="end_turn")


def call(router: LLMRouter, body: dict) -> dict:
    app.dependency_overrides[get_router] = lambda: router
    try:
        response = TestClient(app).post("/posts/rewrite", json=body)
        return {"status": response.status_code, **response.json()}
    finally:
        app.dependency_overrides.clear()


def test_rewrite_endpoint() -> None:
    claude = Recorder("  런치 세트는 19,000원이었는데 가격 대비 만족스러웠어요.  ")

    body = call(LLMRouter({"anthropic": claude}, [Target.parse("anthropic:claude-opus-5")]), INPUT.model_dump())

    assert body["text"] == "런치 세트는 19,000원이었는데 가격 대비 만족스러웠어요."
    assert body["warnings"] == []
    assert body["prompt_version"] == "paragraph-rewrite-v2"
    sent = json.loads(claude.requests[0].prompt)
    assert (sent["request"], sent["tone"], sent["paragraph_before"]) == ("longer", "친근한 말투", "인계동 파스타집에 다녀왔어요.")
    assert claude.requests[0].max_tokens == 4000


def test_rewrite_failure_and_validation() -> None:
    body = call(LLMRouter({}, [Target.parse("anthropic:claude-opus-5")]), INPUT.model_dump())
    assert body["text"] is None and "not_configured" in body["error"]

    assert call(LLMRouter({}, []), {**INPUT.model_dump(), "text": "  "})["status"] == 422
    assert call(LLMRouter({}, []), {**INPUT.model_dump(), "instruction": "funny"})["status"] == 422
