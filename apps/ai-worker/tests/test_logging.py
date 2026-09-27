import json

import pytest
from fastapi.testclient import TestClient

from app.llm.factory import get_router
from app.llm.router import LLMRouter
from app.llm.types import LLMError, LLMRequest, LLMResult, Target
from app.main import app


class Stub:
    provider = "anthropic"

    async def generate(self, request: LLMRequest, model: str) -> LLMResult:
        raise LLMError("no credit", kind="billing")


def lines(capsys: pytest.CaptureFixture[str]) -> list[dict]:
    return [json.loads(line) for line in capsys.readouterr().out.splitlines() if line.startswith("{")]


def test_request_and_llm_attempt_logs_carry_trace_id_without_user_content(capsys: pytest.CaptureFixture[str]) -> None:
    app.dependency_overrides[get_router] = lambda: LLMRouter({"anthropic": Stub()}, [Target.parse("anthropic:claude-opus-5")])
    try:
        TestClient(app).post(
            "/posts/rewrite",
            json={"text": "비밀스러운 사용자 문단", "instruction": "shorter", "facts": [{"fact_key": "메모", "fact_value": "개인 메모"}]},
            headers={"X-Request-Id": "trace-abcdef12"},
        )
    finally:
        app.dependency_overrides.clear()

    entries = lines(capsys)
    attempt = next(e for e in entries if e["message"] == "llm_attempt")
    request = next(e for e in entries if e["message"] == "request")
    assert attempt["trace_id"] == request["trace_id"] == "trace-abcdef12"
    assert (attempt["provider"], attempt["status"], attempt["error_kind"]) == ("anthropic", "failed", "billing")
    assert (request["path"], request["status"]) == ("/posts/rewrite", 200)
    joined = "\n".join(json.dumps(e, ensure_ascii=False) for e in entries)
    assert "비밀스러운" not in joined and "개인 메모" not in joined


def test_invalid_trace_id_is_dropped_and_health_is_not_logged(capsys: pytest.CaptureFixture[str]) -> None:
    client = TestClient(app)
    client.get("/health")
    client.post("/posts/quality-check", json={"keyword": "k", "title": "t", "blocks": []}, headers={"X-Request-Id": "bad id"})

    entries = lines(capsys)
    assert [e["path"] for e in entries if e["message"] == "request"] == ["/posts/quality-check"]
    assert entries[-1]["trace_id"] is None
