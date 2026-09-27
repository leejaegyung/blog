"""사진 분석 (기획서 5.2). 사진만 보고 메뉴명·가격·장소를 확정하지 않는다."""

import io
import json
from typing import Literal

from PIL import Image
from pydantic import BaseModel

from app.generators.writing_plan import FactInput
from app.llm.router import AllTargetsFailed, LLMRouter, RouteOutcome
from app.llm.types import ImageInput, LLMRequest, Target
from app.prompts import load_prompt
from app.storage import resolve_upload_path

PROMPT_VERSION = "photo-analysis-v1"
BATCH_SIZE = 6
MAX_EDGE = 1024  # 토큰을 줄이려고 분석용으로만 줄인다

PhotoType = Literal[
    "food", "drink", "exterior", "interior", "menu_board", "product", "package", "view", "person", "receipt", "other"
]


class PhotoRef(BaseModel):
    id: int
    storage_key: str


class VisionResult(BaseModel):
    id: int
    type: PhotoType
    description: str
    usable: bool
    quality_score: float
    suggested_section: str
    caption_hint: str
    privacy_flags: list[str]


class VisionBatch(BaseModel):
    results: list[VisionResult]


def load_for_vision(storage_key: str) -> bytes:
    with Image.open(resolve_upload_path(storage_key)) as image:
        image = image.convert("RGB")
        image.thumbnail((MAX_EDGE, MAX_EDGE))
        buffer = io.BytesIO()
        image.save(buffer, "JPEG", quality=85)
        return buffer.getvalue()


def build_request(batch: list[PhotoRef], keyword: str, category: str | None, facts: list[FactInput]) -> LLMRequest:
    payload = {
        "keyword": keyword,
        "category": category,
        "facts": [f.model_dump() for f in facts],
        "images": [{"position": i + 1, "id": photo.id} for i, photo in enumerate(batch)],
    }
    return LLMRequest(
        system=load_prompt(PROMPT_VERSION),
        prompt=json.dumps(payload, ensure_ascii=False),
        images=[ImageInput(data=load_for_vision(photo.storage_key)) for photo in batch],
        output_model=VisionBatch,
        max_tokens=8000,
    )


def check_batch(result: VisionBatch, batch: list[PhotoRef]) -> tuple[list[VisionResult], list[int]]:
    """입력한 사진 id의 결과만 남기고(중복은 첫 번째), 빠진 사진 id를 돌려준다."""
    expected = {photo.id for photo in batch}
    kept: dict[int, VisionResult] = {}
    for item in result.results:
        if item.id in expected and item.id not in kept:
            kept[item.id] = item.model_copy(update={"quality_score": round(min(1.0, max(0.0, item.quality_score)), 2)})
    return list(kept.values()), [photo.id for photo in batch if photo.id not in kept]


async def analyze_photos(
    router: LLMRouter,
    photos: list[PhotoRef],
    keyword: str,
    category: str | None,
    facts: list[FactInput],
    route: list[Target] | None = None,
) -> tuple[list[VisionResult], list[int], list[RouteOutcome], list[str]]:
    """배치별로 분석한다. 한 배치가 실패해도 다른 배치 결과는 살린다."""
    results: list[VisionResult] = []
    failed_ids: list[int] = []
    outcomes: list[RouteOutcome] = []
    errors: list[str] = []
    for start in range(0, len(photos), BATCH_SIZE):
        batch = photos[start : start + BATCH_SIZE]
        try:
            outcome = await router.generate(build_request(batch, keyword, category, facts), route)
        except AllTargetsFailed as failed:
            outcomes.append(failed.outcome)
            errors.extend(a.error.kind for a in failed.outcome.attempts if a.error)
            failed_ids.extend(photo.id for photo in batch)
            continue
        outcomes.append(outcome)
        kept, missing = check_batch(outcome.result.parsed, batch)
        results.extend(kept)
        failed_ids.extend(missing)
    return results, failed_ids, outcomes, sorted(set(errors))
