import json
from pathlib import Path

from fastapi.testclient import TestClient
from PIL import Image

from app.llm.factory import get_router
from app.llm.router import LLMRouter
from app.llm.types import LLMError, LLMRequest, LLMResult, Target
from app.main import app
from app.vision.analysis import VisionBatch, VisionResult


def result(image_id: int, score: float = 0.8) -> VisionResult:
    return VisionResult(id=image_id, type="food", description="오일 파스타로 보이는 음식", usable=True,
                        quality_score=score, suggested_section="메인 메뉴", caption_hint="파스타", privacy_flags=[])


class Recorder:
    provider = "anthropic"

    def __init__(self, fail_batches: set[int] = frozenset(), extra: bool = False) -> None:
        self.requests: list[LLMRequest] = []
        self.fail_batches = fail_batches
        self.extra = extra

    async def generate(self, request: LLMRequest, model: str) -> LLMResult:
        self.requests.append(request)
        if len(self.requests) - 1 in self.fail_batches:
            raise LLMError("no credit", kind="billing")
        ids = [img["id"] for img in json.loads(request.prompt)["images"]]
        results = [result(i, score=1.7) for i in ids[1:]] if self.extra else [result(i) for i in ids]
        if self.extra:
            results += [result(999), result(ids[1])]
        return LLMResult(provider="anthropic", model=model, text="{}", parsed=VisionBatch(results=results),
                         input_tokens=5000, output_tokens=600, stop_reason="end_turn")


def photos(root: Path, count: int) -> list[dict]:
    refs = []
    for i in range(count):
        key = f"users/1/posts/1/{i}.jpg"
        (root / key).parent.mkdir(parents=True, exist_ok=True)
        Image.new("RGB", (2048, 1536), "red").save(root / key)
        refs.append({"id": 100 + i, "storage_key": key})
    return refs


def call(recorder: Recorder, images: list[dict]) -> dict:
    app.dependency_overrides[get_router] = lambda: LLMRouter({"anthropic": recorder}, [Target.parse("anthropic:claude-opus-5")])
    try:
        response = TestClient(app).post("/images/analyze", json={
            "keyword": "인계동  파스타", "category": "맛집",
            "facts": [{"fact_key": "대표 메뉴", "fact_value": "봉골레"}], "images": images,
        })
        return {"status": response.status_code, **response.json()}
    finally:
        app.dependency_overrides.clear()


def test_batches_six_images_per_call_and_downscales(upload_root: Path) -> None:
    recorder = Recorder()

    body = call(recorder, photos(upload_root, 8))

    assert [len(r.images) for r in recorder.requests] == [6, 2]
    with Image.open(__import__("io").BytesIO(recorder.requests[0].images[0].data)) as sent:
        assert max(sent.size) == 1024
    sent = json.loads(recorder.requests[1].prompt)
    assert sent["images"] == [{"position": 1, "id": 106}, {"position": 2, "id": 107}]
    assert sent["keyword"] == "인계동 파스타"
    assert len(body["results"]) == 8 and body["failed_ids"] == [] and body["error"] is None
    assert len(body["generations"]) == 2
    assert body["prompt_version"] == "photo-analysis-v1"


def test_failed_batch_keeps_other_batches(upload_root: Path) -> None:
    body = call(Recorder(fail_batches={0}), photos(upload_root, 8))

    assert [r["id"] for r in body["results"]] == [106, 107]
    assert body["failed_ids"] == [100, 101, 102, 103, 104, 105]
    assert "billing" in body["error"]


def test_unknown_duplicate_and_missing_results_are_cleaned(upload_root: Path) -> None:
    body = call(Recorder(extra=True), photos(upload_root, 3))

    assert [r["id"] for r in body["results"]] == [101, 102]  # 999 제거, 101 중복 제거
    assert body["results"][0]["quality_score"] == 1.0  # 범위 밖 점수는 자른다
    assert body["failed_ids"] == [100]


def test_rejects_missing_or_unsafe_files(upload_root: Path) -> None:
    assert call(Recorder(), [{"id": 1, "storage_key": "nope.jpg"}])["status"] == 422
    assert call(Recorder(), [{"id": 1, "storage_key": "../../etc/passwd"}])["status"] == 400
    assert call(Recorder(), [])["status"] == 422
