"""업로드 사진 정규화: 방향 보정 → 리사이즈 → JPEG 재인코딩(EXIF 처리) → 썸네일."""

import warnings
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path

import pillow_heif
from PIL import Image, ImageOps, UnidentifiedImageError

pillow_heif.register_heif_opener()

# 약 8,000만 화소를 넘는 파일은 압축 폭탄으로 보고 거부한다.
Image.MAX_IMAGE_PIXELS = 80_000_000

MAIN_MAX_EDGE = 2048
THUMB_MAX_EDGE = 400
JPEG_QUALITY = 85

EXIF_ORIENTATION = 0x0112
EXIF_DATETIME = 0x0132
EXIF_IFD = 0x8769
EXIF_DATETIME_ORIGINAL = 0x9003
EXIF_GPS_IFD = 0x8825


class InvalidImageError(ValueError):
    pass


@dataclass(frozen=True)
class ProcessedImage:
    main_path: Path
    thumb_path: Path
    width: int
    height: int
    size_bytes: int
    taken_at: datetime | None


def process_image(source: Path, dest_prefix: Path, *, strip_exif: bool) -> ProcessedImage:
    try:
        # Pillow는 한도의 2배까지는 경고만 내므로 경고도 오류로 취급한다.
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(source) as opened:
                opened.load()
                exif = opened.getexif()
                taken_at = _taken_at(exif)
                image = ImageOps.exif_transpose(opened).convert("RGB")
    except (
        UnidentifiedImageError,
        Image.DecompressionBombError,
        Image.DecompressionBombWarning,
        OSError,
        SyntaxError,
    ) as error:
        raise InvalidImageError(str(error)) from error

    image.thumbnail((MAIN_MAX_EDGE, MAIN_MAX_EDGE), Image.Resampling.LANCZOS)

    dest_prefix.parent.mkdir(parents=True, exist_ok=True)
    main_path = dest_prefix.with_name(dest_prefix.name + ".jpg")
    thumb_path = dest_prefix.with_name(dest_prefix.name + "_thumb.jpg")

    save_options: dict = {"quality": JPEG_QUALITY, "optimize": True, "progressive": True}
    if not strip_exif:
        save_options["exif"] = _kept_exif(exif)
    image.save(main_path, "JPEG", **save_options)

    thumb = image.copy()
    thumb.thumbnail((THUMB_MAX_EDGE, THUMB_MAX_EDGE), Image.Resampling.LANCZOS)
    thumb.save(thumb_path, "JPEG", quality=80, optimize=True)

    return ProcessedImage(
        main_path=main_path,
        thumb_path=thumb_path,
        width=image.width,
        height=image.height,
        size_bytes=main_path.stat().st_size,
        taken_at=taken_at,
    )


def _kept_exif(exif: Image.Exif) -> bytes:
    # EXIF를 유지해도 위치 정보는 남기지 않는다. 방향은 이미 픽셀에 반영했으므로 1로 되돌린다.
    exif.pop(EXIF_GPS_IFD, None)
    exif[EXIF_ORIENTATION] = 1
    return exif.tobytes()


def _taken_at(exif: Image.Exif) -> datetime | None:
    raw = exif.get_ifd(EXIF_IFD).get(EXIF_DATETIME_ORIGINAL) or exif.get(EXIF_DATETIME)
    if not raw:
        return None
    try:
        return datetime.strptime(str(raw).strip("\x00 "), "%Y:%m:%d %H:%M:%S")
    except ValueError:
        return None
