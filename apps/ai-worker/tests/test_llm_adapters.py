"""SDK를 실제로 거치되 HTTP만 가짜로 바꿔, 요청 형태와 응답 해석을 검증한다."""

import json

import anthropic
import httpx2
import openai
import pytest
from pydantic import BaseModel

from app.llm.anthropic_adapter import AnthropicAdapter
from app.llm.openai_adapter import OpenAIAdapter
from app.llm.types import ImageInput, LLMError, LLMRequest


class Answer(BaseModel):
    answer: str


def transport(status: int, body: dict, sent: list) -> httpx2.MockTransport:
    def handler(request: httpx2.Request) -> httpx2.Response:
        sent.append(json.loads(request.content))
        return httpx2.Response(status, json=body)

    return httpx2.MockTransport(handler)


def claude(status: int, body: dict, sent: list) -> AnthropicAdapter:
    client = anthropic.AsyncAnthropic(
        api_key="test", max_retries=0, http_client=httpx2.AsyncClient(transport=transport(status, body, sent))
    )
    return AnthropicAdapter(client)


def gpt(status: int, body: dict, sent: list) -> OpenAIAdapter:
    client = openai.AsyncOpenAI(
        api_key="test", max_retries=0, http_client=httpx2.AsyncClient(transport=transport(status, body, sent))
    )
    return OpenAIAdapter(client)


def claude_message(text: str, stop_reason: str = "end_turn", **extra) -> dict:
    return {
        "id": "msg_1", "type": "message", "role": "assistant", "model": "claude-opus-5",
        "content": [{"type": "text", "text": text}], "stop_reason": stop_reason, "stop_sequence": None,
        "usage": {"input_tokens": 100, "output_tokens": 20, "cache_read_input_tokens": 5,
                  "cache_creation_input_tokens": 0},
        **extra,
    }


def gpt_response(text: str, status: str = "completed", content_type: str = "output_text", **extra) -> dict:
    part = {"type": content_type, "annotations": [], "text": text} if content_type == "output_text" \
        else {"type": "refusal", "refusal": text}
    return {
        "id": "resp_1", "object": "response", "created_at": 0, "model": "gpt-5.5", "status": status,
        "output": [{"type": "message", "id": "m1", "status": "completed", "role": "assistant", "content": [part]}],
        "usage": {"input_tokens": 80, "output_tokens": 12, "total_tokens": 92,
                  "input_tokens_details": {"cached_tokens": 0}, "output_tokens_details": {"reasoning_tokens": 0}},
        "parallel_tool_calls": True, "tool_choice": "auto", "tools": [],
        **extra,
    }


IMAGE_REQUEST = LLMRequest(system="sys", prompt="describe", images=[ImageInput(data=b"\xff\xd8img")])


async def test_claude_sends_images_and_reads_usage() -> None:
    sent: list = []

    result = await claude(200, claude_message("a red plate"), sent).generate(IMAGE_REQUEST, "claude-opus-5")

    body = sent[0]
    assert body["system"] == "sys"
    assert body["messages"][0]["content"][0]["source"] == {"type": "base64", "media_type": "image/jpeg", "data": "/9hpbWc="}
    assert body["messages"][0]["content"][1] == {"type": "text", "text": "describe"}
    assert "temperature" not in body
    assert result.text == "a red plate"
    assert (result.input_tokens, result.output_tokens) == (105, 20)


async def test_claude_structured_output() -> None:
    sent: list = []
    request = LLMRequest(system="s", prompt="p", output_model=Answer)

    result = await claude(200, claude_message('{"answer": "pong"}'), sent).generate(request, "claude-opus-5")

    assert sent[0]["output_config"]["format"]["type"] == "json_schema"
    assert result.parsed == Answer(answer="pong")


@pytest.mark.parametrize(
    ("status", "body", "kind"),
    [
        (200, claude_message("", stop_reason="refusal", stop_details={"type": "refusal", "category": "cyber", "explanation": None}), "refused"),
        (200, claude_message("cut", stop_reason="max_tokens"), "truncated"),
        (429, {"type": "error", "error": {"type": "rate_limit_error", "message": "slow"}}, "rate_limited"),
        (529, {"type": "error", "error": {"type": "overloaded_error", "message": "busy"}}, "unavailable"),
        (401, {"type": "error", "error": {"type": "authentication_error", "message": "bad key"}}, "auth"),
        (400, {"type": "error", "error": {"type": "invalid_request_error", "message": "bad"}}, "bad_request"),
        (400, {"type": "error", "error": {"type": "invalid_request_error",
               "message": "Your credit balance is too low to access the Anthropic API."}}, "billing"),
    ],
)
async def test_claude_error_kinds(status: int, body: dict, kind: str) -> None:
    with pytest.raises(LLMError) as error:
        await claude(status, body, []).generate(LLMRequest(system="s", prompt="p"), "claude-opus-5")

    assert error.value.kind == kind


async def test_gpt_sends_images_and_reads_usage() -> None:
    sent: list = []

    result = await gpt(200, gpt_response("a red plate"), sent).generate(IMAGE_REQUEST, "gpt-5.5")

    body = sent[0]
    assert body["instructions"] == "sys"
    assert body["input"][0]["content"][0] == {"type": "input_image", "image_url": "data:image/jpeg;base64,/9hpbWc="}
    assert "temperature" not in body
    assert result.text == "a red plate"
    assert (result.input_tokens, result.output_tokens) == (80, 12)


async def test_gpt_structured_output() -> None:
    sent: list = []
    request = LLMRequest(system="s", prompt="p", output_model=Answer)

    result = await gpt(200, gpt_response('{"answer": "pong"}'), sent).generate(request, "gpt-5.5")

    assert sent[0]["text"]["format"]["type"] == "json_schema"
    assert result.parsed == Answer(answer="pong")


@pytest.mark.parametrize(
    ("status", "body", "kind"),
    [
        (200, gpt_response("I can't help", content_type="refusal"), "refused"),
        (200, gpt_response("cut", status="incomplete", incomplete_details={"reason": "max_output_tokens"}), "truncated"),
        (429, {"error": {"message": "slow", "type": "rate_limit_error"}}, "rate_limited"),
        (429, {"error": {"message": "You exceeded your current quota", "type": "insufficient_quota",
                         "code": "insufficient_quota"}}, "billing"),
        (500, {"error": {"message": "boom", "type": "server_error"}}, "unavailable"),
        (401, {"error": {"message": "bad key", "type": "invalid_request_error"}}, "auth"),
    ],
)
async def test_gpt_error_kinds(status: int, body: dict, kind: str) -> None:
    with pytest.raises(LLMError) as error:
        await gpt(status, body, []).generate(LLMRequest(system="s", prompt="p"), "gpt-5.5")

    assert error.value.kind == kind
