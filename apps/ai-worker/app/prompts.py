from functools import lru_cache
from pathlib import Path

PROMPTS_DIR = Path(__file__).resolve().parent.parent / "prompts"


@lru_cache
def load_prompt(version: str) -> str:
    """버전이 붙은 프롬프트 파일을 읽는다. 내용을 바꿀 때는 새 버전 파일을 만든다(결과 재현을 위해)."""
    return (PROMPTS_DIR / f"{version}.md").read_text(encoding="utf-8")
