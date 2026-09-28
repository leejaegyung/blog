from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    upload_path: str = "/data/uploads"

    anthropic_api_key: str = ""
    openai_api_key: str = ""

    # "provider:model"을 쉼표로 나열한 기본 순서. 앞에서 실패하면 다음으로 넘어간다.
    llm_route: str = "claude_code:opus,codex:gpt-6-astra"
    # SDK 자체 재시도(지수 백오프) 횟수. 이후 다음 공급자로 fallback 한다.
    llm_max_retries: int = 3
    llm_timeout_seconds: float = 300.0

    # Claude 구독(Claude Code) 연결기. 토큰이 없으면 claude_code 대상은 not_configured로 건너뛴다
    claude_bridge_url: str = "http://host.docker.internal:8790"
    claude_bridge_token: str = ""
    # 연결기(claude -p·codex exec 280초)보다 조금 길게 기다린다. Laravel은 이보다 더 길게 기다린다(320초)
    claude_bridge_timeout_seconds: float = 290.0


@lru_cache
def get_settings() -> Settings:
    return Settings()
