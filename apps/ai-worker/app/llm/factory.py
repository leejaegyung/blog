"""요청마다 쓸 LLM 라우터를 만든다.

API 키와 시도 순서는 Laravel 관리 화면에서 바꿀 수 있고, Laravel이 요청 헤더 `X-LLM-Config`(base64 JSON)로 넘긴다.
헤더가 없으면 워커의 환경변수를 쓴다. 워커는 Docker 내부에서만 열려 있고, 이 헤더는 로그에 남기지 않는다.
"""

import base64
import binascii
import hashlib
import json
from collections import OrderedDict
from dataclasses import dataclass

import httpx2
from fastapi import Request

from app.config import get_settings
# from app.llm.anthropic_adapter import AnthropicAdapter  # [API 연결 꺼 둠]
from app.llm.claude_code_adapter import SubscriptionAdapter
# from app.llm.openai_adapter import OpenAIAdapter  # [API 연결 꺼 둠]
from app.llm.router import LLMRouter
from app.llm.types import LLMAdapter, Target

CONFIG_HEADER = "x-llm-config"
_CACHE_SIZE = 8
_routers: "OrderedDict[str, LLMRouter]" = OrderedDict()


@dataclass(frozen=True)
class LLMConfig:
    anthropic_api_key: str
    openai_api_key: str
    route: str


def config_from_header(raw: str | None) -> LLMConfig | None:
    """잘못된 헤더는 무시하고 환경변수 설정으로 돌아간다(요청 자체를 실패시키지 않는다)."""
    if not raw:
        return None
    try:
        data = json.loads(base64.b64decode(raw, validate=True))
    except (binascii.Error, ValueError):
        return None
    if not isinstance(data, dict):
        return None
    return LLMConfig(
        anthropic_api_key=str(data.get("anthropic_api_key") or ""),
        openai_api_key=str(data.get("openai_api_key") or ""),
        route=str(data.get("route") or get_settings().llm_route),
    )


def env_config() -> LLMConfig:
    settings = get_settings()
    return LLMConfig(settings.anthropic_api_key, settings.openai_api_key, settings.llm_route)


def build_router(config: LLMConfig) -> LLMRouter:
    settings = get_settings()
    adapters: dict[str, LLMAdapter] = {}

    # [API 연결 꺼 둠 2026-09-28] 글쓰기는 구독(claude_code·codex)으로만 한다. API로 다시 쓰려면 아래 주석을 풀고
    # Laravel LlmSettings::PROVIDERS·routes/api.php의 키 관리 주석도 함께 푼다. 어댑터 코드(anthropic_adapter·openai_adapter)는 남겨 둔다.
    # if config.anthropic_api_key:
    #     adapters["anthropic"] = AnthropicAdapter(
    #         anthropic.AsyncAnthropic(
    #             api_key=config.anthropic_api_key,
    #             max_retries=settings.llm_max_retries,
    #             timeout=settings.llm_timeout_seconds,
    #         )
    #     )
    # if config.openai_api_key:
    #     adapters["openai"] = OpenAIAdapter(
    #         openai.AsyncOpenAI(
    #             api_key=config.openai_api_key,
    #             max_retries=settings.llm_max_retries,
    #             timeout=settings.llm_timeout_seconds,
    #         )
    #     )

    if settings.claude_bridge_token:
        client = httpx2.AsyncClient()
        for provider in ("claude_code", "codex"):
            adapters[provider] = SubscriptionAdapter(
                provider, client, settings.claude_bridge_url, settings.claude_bridge_token, settings.claude_bridge_timeout_seconds
            )

    route = []
    for spec in config.route.split(","):
        try:
            route.append(Target.parse(spec))
        except ValueError:
            continue  # 잘못된 항목은 건너뛴다
    return LLMRouter(adapters, route)


def get_router(request: Request) -> LLMRouter:
    config = config_from_header(request.headers.get(CONFIG_HEADER)) or env_config()
    # 같은 설정이면 SDK 클라이언트(연결 풀)를 다시 쓴다. 키는 해시로만 캐시 키에 쓴다
    key = hashlib.sha256(repr(config).encode()).hexdigest()
    router = _routers.get(key)
    if router is None:
        router = build_router(config)
        _routers[key] = router
        if len(_routers) > _CACHE_SIZE:
            _routers.popitem(last=False)
    else:
        _routers.move_to_end(key)
    return router
