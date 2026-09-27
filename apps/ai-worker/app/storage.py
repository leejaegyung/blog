from pathlib import Path

from app.config import get_settings


class UnsafePathError(ValueError):
    pass


def resolve_upload_path(relative: str) -> Path:
    """업로드 볼륨 안의 상대 경로만 허용한다(경로 조작 방지)."""
    root = Path(get_settings().upload_path).resolve()
    path = (root / relative).resolve()
    if not path.is_relative_to(root) or path == root:
        raise UnsafePathError(relative)
    return path


def to_storage_key(path: Path) -> str:
    return path.relative_to(Path(get_settings().upload_path).resolve()).as_posix()
