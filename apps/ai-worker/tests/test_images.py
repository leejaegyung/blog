from pathlib import Path

import pillow_heif
from fastapi.testclient import TestClient
from PIL import Image

from app.main import app
from app.vision.processing import EXIF_GPS_IFD, EXIF_IFD, EXIF_ORIENTATION

client = TestClient(app)

EXIF_DATETIME_ORIGINAL = 0x9003


def make_photo(path: Path, size=(4000, 3000), *, orientation: int | None = None, gps: bool = False) -> None:
    image = Image.new("RGB", size, "red")
    exif = Image.Exif()
    exif.get_ifd(EXIF_IFD)[EXIF_DATETIME_ORIGINAL] = "2026:09:20 13:05:00"
    if orientation:
        exif[EXIF_ORIENTATION] = orientation
    if gps:
        exif.get_ifd(EXIF_GPS_IFD)[1] = "N"
    path.parent.mkdir(parents=True, exist_ok=True)
    image.save(path, "JPEG", exif=exif.tobytes())


def post(source: str, dest: str = "users/1/posts/1/abc", strip_exif: bool = True):
    return client.post("/images/process", json={"source": source, "dest_prefix": dest, "strip_exif": strip_exif})


def test_resizes_and_creates_thumbnail(upload_root: Path) -> None:
    make_photo(upload_root / "tmp/a.jpg")

    response = post("tmp/a.jpg")

    assert response.status_code == 200
    body = response.json()
    assert body["storage_key"] == "users/1/posts/1/abc.jpg"
    assert body["thumb_key"] == "users/1/posts/1/abc_thumb.jpg"
    assert (body["width"], body["height"]) == (2048, 1536)
    assert body["taken_at"] == "2026-09-20T13:05:00"
    with Image.open(upload_root / body["thumb_key"]) as thumb:
        assert max(thumb.size) == 400


def test_applies_orientation_and_strips_exif_by_default(upload_root: Path) -> None:
    # orientation 6 = 시계 방향 90도 회전 필요 → 가로 사진이 세로가 된다
    make_photo(upload_root / "tmp/r.jpg", size=(400, 300), orientation=6, gps=True)

    body = post("tmp/r.jpg").json()

    assert (body["width"], body["height"]) == (300, 400)
    with Image.open(upload_root / body["storage_key"]) as saved:
        assert len(saved.getexif()) == 0


def test_keeps_exif_without_gps_when_requested(upload_root: Path) -> None:
    make_photo(upload_root / "tmp/k.jpg", size=(400, 300), orientation=6, gps=True)

    body = post("tmp/k.jpg", strip_exif=False).json()

    with Image.open(upload_root / body["storage_key"]) as saved:
        exif = saved.getexif()
        assert exif.get(EXIF_ORIENTATION) == 1
        assert EXIF_GPS_IFD not in exif
        assert exif.get_ifd(EXIF_IFD).get(EXIF_DATETIME_ORIGINAL) == "2026:09:20 13:05:00"


def test_converts_heic(upload_root: Path) -> None:
    heif = pillow_heif.from_pillow(Image.new("RGB", (640, 480), "blue"))
    heif.save(upload_root / "h.heic")

    body = post("h.heic").json()

    assert body["mime_type"] == "image/jpeg"
    assert (body["width"], body["height"]) == (640, 480)


def test_rejects_non_image(upload_root: Path) -> None:
    (upload_root / "tmp").mkdir()
    (upload_root / "tmp/fake.jpg").write_text("<?php echo 1;")

    assert post("tmp/fake.jpg").status_code == 422


def test_rejects_decompression_bomb(upload_root: Path) -> None:
    Image.new("1", (10_000, 10_000)).save(upload_root / "bomb.png")

    assert post("bomb.png").status_code == 422


def test_rejects_paths_outside_upload_root(upload_root: Path) -> None:
    make_photo(upload_root / "tmp/a.jpg", size=(10, 10))

    assert post("../etc/passwd").status_code == 400
    assert post("tmp/a.jpg", dest="../../tmp/evil").status_code == 400


def test_missing_source(upload_root: Path) -> None:
    assert post("tmp/none.jpg").status_code == 404
