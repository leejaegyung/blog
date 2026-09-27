"""여러 공급자를 순서대로 시도하는 라우터 (기획서 24장: 재시도 → fallback → 실패 기록).

429/5xx/연결 오류의 지수 백오프 재시도는 각 SDK가 max_retries 만큼 처리한다.
라우터는 그래도 실패한 경우, 또는 거절·잘림·키 없음처럼 재시도로 해결되지 않는 경우에 다음 대상으로 넘어간다.
"""

import time
from dataclasses import dataclass, field

from app.llm.types import LLMAdapter, LLMError, LLMRequest, LLMResult, Target
from app.logging_setup import log


@dataclass(frozen=True)
class Attempt:
    target: Target
    latency_ms: int
    result: LLMResult | None = None
    error: LLMError | None = None


@dataclass
class RouteOutcome:
    attempts: list[Attempt] = field(default_factory=list)

    @property
    def result(self) -> LLMResult | None:
        last = self.attempts[-1] if self.attempts else None
        return last.result if last else None


class AllTargetsFailed(Exception):
    def __init__(self, outcome: RouteOutcome) -> None:
        super().__init__("모든 LLM 대상이 실패했습니다.")
        self.outcome = outcome


class LLMRouter:
    def __init__(self, adapters: dict[str, LLMAdapter], default_route: list[Target]) -> None:
        self._adapters = adapters
        self.default_route = default_route

    async def generate(self, request: LLMRequest, route: list[Target] | None = None) -> RouteOutcome:
        outcome = RouteOutcome()

        for target in route or self.default_route:
            adapter = self._adapters.get(target.provider)
            started = time.perf_counter()
            if adapter is None:
                error = LLMError(f"{target.provider} API 키가 설정되지 않았습니다.", kind="not_configured")
                outcome.attempts.append(Attempt(target=target, latency_ms=0, error=error))
                log("llm_attempt", provider=target.provider, model=target.model, status="failed", error_kind="not_configured")
                continue
            try:
                result = await adapter.generate(request, target.model)
            except LLMError as error:
                outcome.attempts.append(Attempt(target=target, latency_ms=_elapsed(started), error=error))
                log("llm_attempt", provider=target.provider, model=target.model, status="failed",
                    error_kind=error.kind, latency_ms=_elapsed(started))
                continue
            outcome.attempts.append(Attempt(target=target, latency_ms=_elapsed(started), result=result))
            log("llm_attempt", provider=result.provider, model=result.model, status="success",
                latency_ms=_elapsed(started), input_tokens=result.input_tokens, output_tokens=result.output_tokens)
            return outcome

        raise AllTargetsFailed(outcome)


def _elapsed(started: float) -> int:
    return int((time.perf_counter() - started) * 1000)
