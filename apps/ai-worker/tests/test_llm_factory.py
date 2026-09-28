import base64
import json

import httpx2
import pytest

from fastapi.testclient import TestClient

from app.config import get_settings
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

    # [API 연결 꺼 둠] 키가 있어도 API 공급자는 등록하지 않는다(구독 연결기 토큰이 없으면 아무것도 없음)
    assert set(router._adapters) == set()
    assert [str(t) for t in router.default_route] == ["openai:gpt-5.4-mini", "anthropic:claude-sonnet-5"]


def test_invalid_header_falls_back_to_environment() -> None:
    assert factory.config_from_header("not base64!") is None
    assert factory.config_from_header(header(["list"])) is None
    assert factory.config_from_header(None) is None


def test_bad_route_entries_are_skipped() -> None:
    router = factory.build_router(factory.LLMConfig("", "", "gemini:x, ,openai:gpt-5.5"))

    assert [str(t) for t in router.default_route] == ["openai:gpt-5.5"]


@pytest.mark.skip(reason="[API 연결 꺼 둠 2026-09-28] factory의 API 어댑터 등록을 다시 켜면 되살린다")
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


def test_models_come_from_bridge_or_explain_why_not(monkeypatch) -> None:
    client = TestClient(app)
    assert "CLAUDE_BRIDGE_TOKEN" in client.get("/llm/models").json()["error"]

    monkeypatch.setattr(get_settings(), "claude_bridge_token", "tok")
    bridge = httpx2.MockTransport(lambda request: httpx2.Response(200, json={
        "claude_code": [{"id": "opus", "label": "Opus", "description": "최고"}],
        "codex": [{"id": "gpt-6-astra", "label": "GPT-6-Astra", "description": ""}],
    }) if request.headers["authorization"] == "Bearer tok" else httpx2.Response(401))
    real = httpx2.AsyncClient
    monkeypatch.setattr(httpx2, "AsyncClient", lambda **kw: real(transport=bridge, **kw))

    body = client.get("/llm/models").json()
    assert [m["id"] for m in body["codex"]] == ["gpt-6-astra"] and body["claude_code"][0]["label"] == "Opus"
    assert body["error"] is None
