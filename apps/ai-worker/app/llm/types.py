from dataclasses import dataclass, field
from typing import Literal, Protocol

from pydantic import BaseModel

Provider = Literal["anthropic", "openai"]


@dataclass(frozen=True)
class ImageInput:
    data: bytes
    media_type: str = "image/jpeg"


@dataclass(frozen=True)
class LLMRequest:
    system: str
    prompt: str
    images: list[ImageInput] = field(default_factory=list)
    # 지정하면 공급자의 structured output으로 이 스키마에 맞는 JSON을 강제한다.
    output_model: type[BaseModel] | None = None
    max_tokens: int = 16000


@dataclass(frozen=True)
class LLMResult:
    provider: Provider
    model: str
    text: str
    parsed: BaseModel | None
    input_tokens: int
    output_tokens: int
    stop_reason: str | None


@dataclass(frozen=True)
class Target:
    provider: Provider
    model: str

    @classmethod
    def parse(cls, spec: str) -> "Target":
        provider, _, model = spec.strip().partition(":")
        if provider not in ("anthropic", "openai") or not model:
            raise ValueError(f"잘못된 LLM 대상: {spec!r} (예: anthropic:claude-opus-5)")
        return cls(provider=provider, model=model)  # type: ignore[arg-type]

    def __str__(self) -> str:
        return f"{self.provider}:{self.model}"


class LLMAdapter(Protocol):
    provider: Provider

    async def generate(self, request: LLMRequest, model: str) -> LLMResult: ...


class LLMError(Exception):
    """공급자 호출 실패. 라우터는 이 오류가 나면 다음 대상으로 넘어간다."""

    def __init__(self, message: str, *, kind: str) -> None:
        super().__init__(message)
        # not_configured | billing | rate_limited | unavailable | bad_request | auth | refused | truncated | invalid_output
        self.kind = kind
