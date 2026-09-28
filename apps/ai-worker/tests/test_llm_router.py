import pytest

from app.llm.router import AllTargetsFailed, LLMRouter
from app.llm.types import LLMError, LLMRequest, LLMResult, Target

REQUEST = LLMRequest(system="s", prompt="p")


class FakeAdapter:
    def __init__(self, provider: str, outcomes: list) -> None:
        self.provider = provider
        self.outcomes = outcomes
        self.calls: list[str] = []

    async def generate(self, request: LLMRequest, model: str) -> LLMResult:
        self.calls.append(model)
        outcome = self.outcomes.pop(0)
        if isinstance(outcome, Exception):
            raise outcome
        return LLMResult(
            provider=self.provider, model=model, text=outcome, parsed=None,
            input_tokens=10, output_tokens=5, stop_reason="end_turn",
        )


def route(*specs: str) -> list[Target]:
    return [Target.parse(s) for s in specs]


async def test_uses_first_target_when_it_succeeds() -> None:
    claude = FakeAdapter("anthropic", ["hello"])
    gpt = FakeAdapter("openai", ["unused"])
    router = LLMRouter({"anthropic": claude, "openai": gpt}, route("anthropic:claude-opus-5", "openai:gpt-5.5"))

    outcome = await router.generate(REQUEST)

    assert outcome.result.text == "hello"
    assert len(outcome.attempts) == 1
    assert gpt.calls == []


async def test_falls_back_to_next_provider_and_records_failure() -> None:
    claude = FakeAdapter("anthropic", [LLMError("overloaded", kind="unavailable")])
    gpt = FakeAdapter("openai", ["from gpt"])
    router = LLMRouter({"anthropic": claude, "openai": gpt}, route("anthropic:claude-opus-5", "openai:gpt-5.5"))

    outcome = await router.generate(REQUEST)

    assert outcome.result.provider == "openai"
    assert [a.error.kind if a.error else "ok" for a in outcome.attempts] == ["unavailable", "ok"]


async def test_skips_provider_without_api_key() -> None:
    gpt = FakeAdapter("openai", ["ok"])
    router = LLMRouter({"openai": gpt}, route("anthropic:claude-opus-5", "openai:gpt-5.5"))

    outcome = await router.generate(REQUEST)

    assert outcome.attempts[0].error.kind == "not_configured"
    assert outcome.result.text == "ok"


async def test_explicit_route_overrides_default() -> None:
    claude = FakeAdapter("anthropic", ["claude"])
    gpt = FakeAdapter("openai", ["gpt"])
    router = LLMRouter({"anthropic": claude, "openai": gpt}, route("anthropic:claude-opus-5"))

    outcome = await router.generate(REQUEST, route("openai:gpt-5.4-mini"))

    assert outcome.result.text == "gpt"
    assert gpt.calls == ["gpt-5.4-mini"]


async def test_raises_with_all_attempts_when_everything_fails() -> None:
    claude = FakeAdapter("anthropic", [LLMError("no", kind="refused")])
    gpt = FakeAdapter("openai", [LLMError("slow", kind="rate_limited")])
    router = LLMRouter({"anthropic": claude, "openai": gpt}, route("anthropic:claude-opus-5", "openai:gpt-5.5"))

    with pytest.raises(AllTargetsFailed) as failed:
        await router.generate(REQUEST)

    assert [a.error.kind for a in failed.value.outcome.attempts] == ["refused", "rate_limited"]


def test_target_parse_rejects_unknown_provider() -> None:
    with pytest.raises(ValueError):
        Target.parse("gemini:pro")


async def test_timeout_stops_without_trying_other_models() -> None:
    # 시간 초과면 앞 호출이 이미 사용량을 썼을 수 있어 다음 모델로 넘어가지 않는다
    claude = FakeAdapter("claude_code", [LLMError("too slow", kind="timeout")])
    gpt = FakeAdapter("codex", ["unused"])
    router = LLMRouter({"claude_code": claude, "codex": gpt}, route("claude_code:opus", "codex:gpt-6-astra"))

    with pytest.raises(AllTargetsFailed) as failed:
        await router.generate(REQUEST)

    assert [a.error.kind for a in failed.value.outcome.attempts] == ["timeout"]
    assert gpt.calls == []
