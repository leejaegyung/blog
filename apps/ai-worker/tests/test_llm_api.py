from fastapi.testclient import TestClient

from app.llm.factory import get_router
from app.llm.router import LLMRouter
from app.llm.types import LLMError, LLMRequest, LLMResult, Target
from app.main import app


class Stub:
    def __init__(self, provider: str, error: LLMError | None = None) -> None:
        self.provider = provider
        self.error = error

    async def generate(self, request: LLMRequest, model: str) -> LLMResult:
        if self.error:
            raise self.error
        return LLMResult(provider=self.provider, model=model, text=" pong\n", parsed=None,
                         input_tokens=12, output_tokens=3, stop_reason="end_turn")


def client_with(router: LLMRouter) -> TestClient:
    app.dependency_overrides[get_router] = lambda: router
    return TestClient(app)


def teardown_function() -> None:
    app.dependency_overrides.clear()


def test_ping_returns_generation_meta_for_every_attempt() -> None:
    router = LLMRouter(
        {"anthropic": Stub("anthropic", LLMError("busy", kind="unavailable")), "openai": Stub("openai")},
        [Target.parse("anthropic:claude-opus-5"), Target.parse("openai:gpt-5.5")],
    )

    body = client_with(router).post("/llm/ping", json={}).json()

    assert body["text"] == "pong"
    assert [(g["provider"], g["status"], g["error_kind"]) for g in body["generations"]] == [
        ("anthropic", "failed", "unavailable"),
        ("openai", "success", None),
    ]
    assert body["generations"][1]["input_tokens"] == 12


def test_ping_503_when_nothing_is_configured() -> None:
    router = LLMRouter({}, [Target.parse("anthropic:claude-opus-5")])

    response = client_with(router).post("/llm/ping", json={})

    assert response.status_code == 503
    assert response.json()["detail"]["generations"][0]["error_kind"] == "not_configured"


def test_ping_rejects_bad_target() -> None:
    response = client_with(LLMRouter({}, [])).post("/llm/ping", json={"targets": ["gemini:x"]})

    assert response.status_code == 422
