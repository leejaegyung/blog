import base64

import openai

from app.llm.types import LLMError, LLMRequest, LLMResult


class OpenAIAdapter:
    provider = "openai"

    def __init__(self, client: openai.AsyncOpenAI) -> None:
        self._client = client

    async def generate(self, request: LLMRequest, model: str) -> LLMResult:
        content: list[dict] = [
            {
                "type": "input_image",
                "image_url": f"data:{image.media_type};base64,{base64.b64encode(image.data).decode()}",
            }
            for image in request.images
        ]
        content.append({"type": "input_text", "text": request.prompt})
        params = {
            "model": model,
            "instructions": request.system,
            "input": [{"role": "user", "content": content}],
            "max_output_tokens": request.max_tokens,
        }

        try:
            if request.output_model:
                response = await self._client.responses.parse(**params, text_format=request.output_model)
            else:
                response = await self._client.responses.create(**params)
        except (openai.AuthenticationError, openai.PermissionDeniedError) as error:
            raise LLMError(str(error), kind="auth") from error
        except openai.RateLimitError as error:
            # OpenAI는 한도·잔액 소진을 429 insufficient_quota로 보낸다
            kind = "billing" if "insufficient_quota" in str(error) else "rate_limited"
            raise LLMError(str(error), kind=kind) from error
        except openai.BadRequestError as error:
            raise LLMError(str(error), kind="bad_request") from error
        except openai.APIStatusError as error:
            kind = "unavailable" if error.status_code >= 500 else "bad_request"
            raise LLMError(str(error), kind=kind) from error
        except openai.APIConnectionError as error:
            raise LLMError(str(error), kind="unavailable") from error

        refusal = next(
            (
                part.refusal
                for item in response.output
                if item.type == "message"
                for part in item.content
                if part.type == "refusal"
            ),
            None,
        )
        if refusal:
            raise LLMError(f"거절됨: {refusal}", kind="refused")
        if response.status == "incomplete":
            reason = response.incomplete_details.reason if response.incomplete_details else None
            raise LLMError(f"응답이 완료되지 않았습니다 ({reason}).", kind="truncated")

        parsed = getattr(response, "output_parsed", None) if request.output_model else None
        if request.output_model and parsed is None:
            raise LLMError("구조화 출력을 파싱하지 못했습니다.", kind="invalid_output")

        usage = response.usage
        return LLMResult(
            provider="openai",
            model=response.model,
            text=response.output_text,
            parsed=parsed,
            input_tokens=usage.input_tokens if usage else 0,
            output_tokens=usage.output_tokens if usage else 0,
            stop_reason=response.status,
        )
