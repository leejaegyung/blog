import base64

import anthropic

from app.llm.types import LLMError, LLMRequest, LLMResult


class AnthropicAdapter:
    provider = "anthropic"

    def __init__(self, client: anthropic.AsyncAnthropic) -> None:
        self._client = client

    async def generate(self, request: LLMRequest, model: str) -> LLMResult:
        content: list[dict] = [
            {
                "type": "image",
                "source": {
                    "type": "base64",
                    "media_type": image.media_type,
                    "data": base64.standard_b64encode(image.data).decode(),
                },
            }
            for image in request.images
        ]
        content.append({"type": "text", "text": request.prompt})
        params = {
            "model": model,
            "max_tokens": request.max_tokens,
            "system": request.system,
            "messages": [{"role": "user", "content": content}],
        }

        try:
            if request.output_model:
                response = await self._client.messages.parse(**params, output_format=request.output_model)
            else:
                response = await self._client.messages.create(**params)
        except anthropic.AuthenticationError as error:
            raise LLMError(str(error), kind="auth") from error
        except anthropic.PermissionDeniedError as error:
            raise LLMError(str(error), kind="auth") from error
        except anthropic.RateLimitError as error:
            raise LLMError(str(error), kind="rate_limited") from error
        except anthropic.BadRequestError as error:
            # 크레딧 부족은 400으로 오지만 요청 문제가 아니라 계정 결제 문제다
            kind = "billing" if "credit balance" in str(error).lower() else "bad_request"
            raise LLMError(str(error), kind=kind) from error
        except anthropic.APIStatusError as error:
            kind = "unavailable" if error.status_code >= 500 else "bad_request"
            raise LLMError(str(error), kind=kind) from error
        except anthropic.APIConnectionError as error:
            raise LLMError(str(error), kind="unavailable") from error

        if response.stop_reason == "refusal":
            category = response.stop_details.category if response.stop_details else None
            raise LLMError(f"거절됨 (category={category})", kind="refused")
        if response.stop_reason == "max_tokens":
            raise LLMError("max_tokens에 도달해 응답이 잘렸습니다.", kind="truncated")

        text = "".join(block.text for block in response.content if block.type == "text")
        parsed = getattr(response, "parsed_output", None) if request.output_model else None
        if request.output_model and parsed is None:
            raise LLMError("구조화 출력을 파싱하지 못했습니다.", kind="invalid_output")

        usage = response.usage
        return LLMResult(
            provider="anthropic",
            model=response.model,
            text=text,
            parsed=parsed,
            # 캐시 읽기/쓰기 토큰도 입력으로 합산한다
            input_tokens=usage.input_tokens
            + (usage.cache_creation_input_tokens or 0)
            + (usage.cache_read_input_tokens or 0),
            output_tokens=usage.output_tokens,
            stop_reason=response.stop_reason,
        )
