import base64
import json

from fastapi.testclient import TestClient

from app.llm import factory
from app.llm.openai_adapter import OpenAIAdapter
from app.llm.types import LLMResult
from app.main import app


def header(data: object) -> str:
    return base64.b64encode(json.dumps(data).encode()).decode()


def test_header_config_overrides_environment() -> None:
    config = factory.config_from_header(header({
        "anthropic_api_key": "sk-ant-test", "openai_api_key": "", "route": "openai:gpt-5.4-mini,anthropic:claude-sonnet-5",
    }))

    router = factory.build_router(config)

    assert set(router._adapters) == {"anthropic"}
    assert [str(t) for t in router.default_route] == ["openai:gpt-5.4-mini", "anthropic:claude-sonnet-5"]


def test_invalid_header_falls_back_to_environment() -> None:
    assert factory.config_from_header("not base64!") is None
    assert factory.config_from_header(header(["list"])) is None
    assert factory.config_from_header(None) is None


def test_bad_route_entries_are_skipped() -> None:
    router = factory.build_router(factory.LLMConfig("", "", "gemini:x, ,openai:gpt-5.5"))

    assert [str(t) for t in router.default_route] == ["openai:gpt-5.5"]


def test_ping_uses_keys_from_header_without_restart(monkeypatch) -> None:
    seen: list[str] = []

    async def fake_generate(self, request, model):  # 실제 API는 부르지 않는다
        seen.append(self._client.api_key)
        return LLMResult(provider="openai", model=model, text="pong", parsed=None,
                         input_tokens=1, output_tokens=1, stop_reason="completed")

    monkeypatch.setattr(OpenAIAdapter, "generate", fake_generate)
    client = TestClient(app)

    without = client.post("/llm/ping", json={}).json()["detail"]["generations"]
    assert {g["error_kind"] for g in without} == {"not_configured"}

    response = client.post("/llm/ping", json={},
                           headers={"X-LLM-Config": header({"openai_api_key": "sk-from-admin", "route": "openai:gpt-5.5"})})
    assert response.json()["text"] == "pong"
    assert seen == ["sk-from-admin"]


def test_routers_are_cached_per_config() -> None:
    class Req:
        def __init__(self, raw: str) -> None:
            self.headers = {"x-llm-config": raw}

    raw = header({"anthropic_api_key": "sk-ant-a", "route": "anthropic:claude-opus-5"})
    assert factory.get_router(Req(raw)) is factory.get_router(Req(raw))
    assert factory.get_router(Req(raw)) is not factory.get_router(Req(header({"anthropic_api_key": "sk-ant-b"})))
