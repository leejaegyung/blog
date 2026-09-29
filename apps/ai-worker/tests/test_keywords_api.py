import json

from fastapi.testclient import TestClient

from app.generators.keyword_insight import KeywordInsight
from app.llm.factory import get_router
from app.llm.router import LLMRouter
from app.llm.types import LLMError, LLMRequest, LLMResult, Target
from app.main import app
from tests.test_aggregate import DOCS

INSIGHT = KeywordInsight(
    primary_intent="맛집 방문 후기",
    intent_distribution=[{"label": "맛집 방문 후기", "share": 0.7}, {"label": "메뉴·가격 정보", "share": 0.3}],
    must_answer=["주차가 되나요?", "대표 메뉴는 무엇인가요?"],
    recommended_outline=[{"heading": "위치와 주차", "purpose": "찾아가는 방법", "photo_hint": "외관 1장"}],
    title_guidelines=["키워드를 앞에 두세요"],
    related_keywords=["주차", "메뉴"],
    writing_tips=["사진 2~3장씩 묶으세요"],
    hashtags=["#인계동 맛집", "데이트코스", "인계동파스타"],
)


class Recorder:
    def __init__(self, provider: str, error: LLMError | None = None) -> None:
        self.provider = provider
        self.error = error
        self.requests: list[LLMRequest] = []

    async def generate(self, request: LLMRequest, model: str) -> LLMResult:
        self.requests.append(request)
        if self.error:
            raise self.error
        return LLMResult(provider=self.provider, model=model, text="{}", parsed=INSIGHT,
                         input_tokens=900, output_tokens=400, stop_reason="end_turn")


def call(router: LLMRouter, features=DOCS) -> dict:
    app.dependency_overrides[get_router] = lambda: router
    try:
        return TestClient(app).post("/keywords/analyze", json={
            "keyword": "인계동  파스타", "category": "맛집", "features": [f.model_dump() for f in features],
        }).json()
    finally:
        app.dependency_overrides.clear()


def test_returns_stats_insight_and_generation_meta() -> None:
    claude = Recorder("anthropic")
    body = call(LLMRouter({"anthropic": claude}, [Target.parse("anthropic:claude-opus-5")]))

    assert body["stats"]["reference_count"] == 3
    assert body["insight"]["primary_intent"] == "맛집 방문 후기"
    assert body["prompt_version"] == "keyword-analysis-v3"
    assert body["generations"][0]["input_tokens"] == 900

    request = claude.requests[0]
    assert request.output_model is KeywordInsight
    sent = json.loads(request.prompt)
    assert sent["keyword"] == "인계동 파스타"
    assert sent["reference_statistics"]["reference_count"] == 3


def test_prompt_contains_no_reference_sentences() -> None:
    claude = Recorder("anthropic")
    call(LLMRouter({"anthropic": claude}, [Target.parse("anthropic:claude-opus-5")]))

    prompt = claude.requests[0].prompt
    for sentence in ("주차는 어려워요", "크림 파스타가 맛있었어요", "라자냐를 먹었습니다"):
        assert sentence not in prompt


def test_stats_survive_llm_failure() -> None:
    router = LLMRouter({}, [Target.parse("anthropic:claude-opus-5"), Target.parse("openai:gpt-5.5")])

    body = call(router)

    assert body["insight"] is None
    assert "not_configured" in body["insight_error"]
    assert body["stats"]["reference_count"] == 3
    assert [g["status"] for g in body["generations"]] == ["failed", "failed"]


def test_works_without_references() -> None:
    claude = Recorder("anthropic")

    body = call(LLMRouter({"anthropic": claude}, [Target.parse("anthropic:claude-opus-5")]), features=[])

    assert body["stats"] is None
    assert json.loads(claude.requests[0].prompt)["reference_statistics"] is None


def test_guide_merges_keyword_reference_and_ai_hashtags() -> None:
    claude = Recorder("anthropic")
    body = call(LLMRouter({"anthropic": claude}, [Target.parse("anthropic:claude-opus-5")]))

    guide = body["guide"]
    tags = [h["tag"] for h in guide["hashtags"]]
    # 키워드 조합이 먼저, AI 태그는 정규화·중복 제거(인계동파스타는 이미 있음)
    assert tags[:2] == ["인계동파스타", "인계동맛집"]
    assert "데이트코스" in tags and tags.count("인계동파스타") == 1
    assert {h["source"] for h in guide["hashtags"]} >= {"keyword", "ai"}
    assert guide["reference_count"] == 3
    assert any(t["key"] == "length" for t in guide["targets"])


def test_guide_survives_llm_failure_without_ai_tags() -> None:
    body = call(LLMRouter({}, [Target.parse("anthropic:claude-opus-5")]), features=[])

    assert body["insight"] is None
    assert body["guide"]["reference_count"] == 0
    assert [h["source"] for h in body["guide"]["hashtags"]] == ["keyword", "keyword"]


def test_platform_is_sent_to_the_prompt_and_guide() -> None:
    claude = Recorder("anthropic")
    app.dependency_overrides[get_router] = lambda: LLMRouter({"anthropic": claude}, [Target.parse("anthropic:claude-opus-5")])
    try:
        body = TestClient(app).post("/keywords/analyze", json={"keyword": "인계동 파스타", "features": [], "platform": "tistory"}).json()
    finally:
        app.dependency_overrides.clear()

    assert json.loads(claude.requests[0].prompt)["platform"] == "tistory"
    assert any("구글" in p for p in body["guide"]["principles"])
