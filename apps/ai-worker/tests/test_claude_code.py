"""구독 어댑터(Claude Code·Codex): claude-bridge와 주고받는 형태와 오류 분류."""

import json

import httpx2
import pytest
from pydantic import BaseModel

from app.config import get_settings
from app.llm import factory
from app.llm.claude_code_adapter import SubscriptionAdapter
from app.llm.types import ImageInput, LLMError, LLMRequest, Target


class Answer(BaseModel):
    answer: str


def adapter(handler, provider: str = "claude_code") -> SubscriptionAdapter:
    return SubscriptionAdapter(provider, httpx2.AsyncClient(transport=httpx2.MockTransport(handler)), "http://bridge:8790/", "tok")


async def test_sends_schema_images_and_reads_structured_output() -> None:
    sent: list = []

    def handler(request: httpx2.Request) -> httpx2.Response:
        sent.append((request.url.path, request.headers["authorization"], json.loads(request.content)))
        return httpx2.Response(200, json={
            "ok": True, "text": '{"answer":"pong"}', "structured": {"answer": "pong"}, "model": "claude-opus-5",
            "input_tokens": 900, "output_tokens": 40, "stop_reason": "end_turn",
        })

    request = LLMRequest(system="sys", prompt="ping", output_model=Answer, images=[ImageInput(data=b"\xff\xd8img")])
    result = await adapter(handler).generate(request, "opus")

    path, auth, body = sent[0]
    assert (path, auth) == ("/generate", "Bearer tok")
    assert body["provider"] == "claude_code"
    assert body["model"] == "opus" and body["system"] == "sys" and body["prompt"] == "ping"
    assert body["json_schema"]["properties"]["answer"]["type"] == "string"
    assert body["images"] == [{"media_type": "image/jpeg", "data": "/9hpbWc="}]
    assert result.parsed == Answer(answer="pong")
    assert (result.provider, result.model, result.input_tokens, result.output_tokens) == ("claude_code", "claude-opus-5", 900, 40)


async def test_falls_back_to_parsing_text_when_no_structured_output() -> None:
    def handler(_: httpx2.Request) -> httpx2.Response:
        return httpx2.Response(200, json={"ok": True, "text": '{"answer": "hi"}', "structured": None})

    result = await adapter(handler).generate(LLMRequest(system="s", prompt="p", output_model=Answer), "sonnet")

    assert result.parsed == Answer(answer="hi")


@pytest.mark.parametrize(
    ("response", "kind"),
    [
        (httpx2.Response(401, json={"error": "토큰"}), "auth"),
        (httpx2.Response(500, json={}), "unavailable"),
        (httpx2.Response(200, json={"ok": False, "error": "Claude usage limit reached", "api_error_status": 429}), "rate_limited"),
        (httpx2.Response(200, json={"ok": False, "error": "Please run /login", "api_error_status": None}), "auth"),
        (httpx2.Response(200, json={"ok": False, "error": "timeout", "error_kind": "unavailable"}), "unavailable"),
        (httpx2.Response(200, json={"ok": True, "text": "not json", "structured": None}), "invalid_output"),
    ],
)
async def test_error_kinds(response: httpx2.Response, kind: str) -> None:
    with pytest.raises(LLMError) as error:
        await adapter(lambda _: response).generate(LLMRequest(system="s", prompt="p", output_model=Answer), "opus")

    assert error.value.kind == kind


async def test_bridge_down_is_unavailable_with_how_to_start() -> None:
    def handler(request: httpx2.Request) -> httpx2.Response:
        raise httpx2.ConnectError("refused", request=request)

    with pytest.raises(LLMError) as error:
        await adapter(handler).generate(LLMRequest(system="s", prompt="p"), "opus")

    assert error.value.kind == "unavailable"
    assert "make claude-bridge" in str(error.value)


def test_registered_only_when_bridge_token_is_set(monkeypatch) -> None:
    assert Target.parse("claude_code:opus").provider == "claude_code"
    assert "claude_code" not in factory.build_router(factory.LLMConfig("", "", "claude_code:opus"))._adapters

    monkeypatch.setattr(get_settings(), "claude_bridge_token", "tok")
    router = factory.build_router(factory.LLMConfig("", "", "claude_code:opus,openai:gpt-5.5"))

    assert set(router._adapters) == {"claude_code", "codex"}
    assert [str(t) for t in router.default_route] == ["claude_code:opus", "openai:gpt-5.5"]


class Optional_(BaseModel):
    answer: str
    tags: list[str] = []


async def test_codex_gets_strict_schema_and_its_own_provider() -> None:
    sent: list = []

    def handler(request: httpx2.Request) -> httpx2.Response:
        sent.append(json.loads(request.content))
        return httpx2.Response(200, json={"ok": True, "text": '{"answer":"a","tags":[]}', "structured": {"answer": "a", "tags": []},
                                          "model": "gpt-5.5", "input_tokens": 10, "output_tokens": 2})

    result = await adapter(handler, "codex").generate(LLMRequest(system="s", prompt="p", output_model=Optional_), "gpt-5.5")

    schema = sent[0]["json_schema"]
    assert sent[0]["provider"] == "codex"
    assert schema["additionalProperties"] is False and set(schema["required"]) == {"answer", "tags"}
    assert (result.provider, result.parsed) == ("codex", Optional_(answer="a"))


async def test_codex_login_expired_is_auth() -> None:
    response = httpx2.Response(200, json={"ok": False, "error": "workspace routing discovery unauthorized (401)"})
    with pytest.raises(LLMError) as error:
        await adapter(lambda _: response, "codex").generate(LLMRequest(system="s", prompt="p"), "gpt-5.5")

    assert error.value.kind == "auth"
