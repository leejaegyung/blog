"""구독으로 생성하는 어댑터 — Claude 구독(Claude Code, `claude_code`)과 ChatGPT 구독(Codex CLI, `codex`).

API 크레딧 없이, 호스트(Mac)에서 도는 claude-bridge(`infra/claude-bridge/bridge.py`)가 로그인된 CLI를
헤드리스로 실행한다. 워커는 Docker 안에 있으므로 bridge에는 host.docker.internal로 닿는다.
"""

import base64
import json

import httpx2
from openai.lib._pydantic import to_strict_json_schema
from pydantic import ValidationError

from app.llm.types import LLMError, LLMRequest, LLMResult

BRIDGE_DOWN = "구독 연결기(claude-bridge)가 꺼져 있습니다. Mac에서 `make claude-bridge`를 실행하세요."
LABELS = {"claude_code": "구독 Claude", "codex": "구독 ChatGPT"}


class SubscriptionAdapter:
    def __init__(self, provider: str, client: httpx2.AsyncClient, url: str, token: str, timeout: float = 300.0) -> None:
        self.provider = provider
        self._client = client
        self._url = url.rstrip("/")
        self._token = token
        self._timeout = timeout

    async def generate(self, request: LLMRequest, model: str) -> LLMResult:
        schema = None
        if request.output_model:
            # Codex(OpenAI)는 모든 필드 required·additionalProperties false인 엄격한 스키마만 받는다
            schema = (to_strict_json_schema(request.output_model) if self.provider == "codex"
                      else request.output_model.model_json_schema())
        payload = {
            "provider": self.provider,
            "system": request.system,
            "prompt": request.prompt,
            "model": model,
            "json_schema": schema,
            "images": [
                {"media_type": image.media_type, "data": base64.standard_b64encode(image.data).decode()}
                for image in request.images
            ],
        }
        try:
            response = await self._client.post(
                f"{self._url}/generate",
                json=payload,
                headers={"Authorization": f"Bearer {self._token}"},
                timeout=self._timeout,
            )
        except httpx2.TimeoutException as error:
            raise LLMError(f"{LABELS.get(self.provider, '구독')}이 제시간에 답하지 않았습니다.", kind="timeout") from error
        except httpx2.HTTPError as error:
            raise LLMError(BRIDGE_DOWN, kind="unavailable") from error

        if response.status_code == 401:
            raise LLMError("연결기 토큰(CLAUDE_BRIDGE_TOKEN)이 맞지 않습니다.", kind="auth")
        if response.status_code != 200:
            raise LLMError(f"연결기 오류 ({response.status_code})", kind="unavailable")

        data = response.json()
        if not data.get("ok"):
            message = str(data.get("error") or f"{LABELS.get(self.provider, '구독')} 호출 실패")
            raise LLMError(message, kind=data.get("error_kind") or _kind(message, data.get("api_error_status")))

        text = str(data.get("text") or "")
        parsed = None
        if request.output_model:
            raw = data.get("structured")
            try:
                if raw is None:
                    raw = json.loads(text)
                parsed = request.output_model.model_validate(raw)
            except (ValueError, ValidationError) as error:
                raise LLMError("구조화 출력을 파싱하지 못했습니다.", kind="invalid_output") from error

        return LLMResult(
            provider=self.provider,  # type: ignore[arg-type]
            model=str(data.get("model") or model),
            text=text,
            parsed=parsed,
            input_tokens=int(data.get("input_tokens") or 0),
            output_tokens=int(data.get("output_tokens") or 0),
            stop_reason=data.get("stop_reason"),
        )


def _kind(message: str, status: int | None) -> str:
    lowered = message.lower()
    if status == 429 or "limit" in lowered or "한도" in message:
        return "rate_limited"  # 구독 사용 한도 → 다음 대상으로 넘어간다
    if status in (401, 403) or "401" in lowered or "unauthorized" in lowered or "login" in lowered or "auth" in lowered:
        return "auth"
    if "refus" in lowered:
        return "refused"
    return "unavailable"
