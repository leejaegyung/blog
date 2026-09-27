"""구조화 로그(기획서 23장). JSON 한 줄, trace_id 포함. 프롬프트·사실·본문 같은 사용자 내용은 남기지 않는다."""

import json
import logging
import sys
from contextvars import ContextVar
from datetime import UTC, datetime

trace_id: ContextVar[str | None] = ContextVar("trace_id", default=None)

logger = logging.getLogger("blog_ai")


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        entry = {
            "time": datetime.fromtimestamp(record.created, UTC).isoformat(timespec="milliseconds"),
            "level": record.levelname.lower(),
            "message": record.getMessage(),
            "trace_id": trace_id.get(),
            **getattr(record, "fields", {}),
        }
        if record.exc_info:
            entry["error"] = self.formatException(record.exc_info).splitlines()[-1]
        return json.dumps(entry, ensure_ascii=False)


class StdoutHandler(logging.Handler):
    """쓰는 순간의 sys.stdout에 쓴다(StreamHandler는 만들 때의 stream을 붙잡는다)."""

    def emit(self, record: logging.LogRecord) -> None:
        try:
            sys.stdout.write(self.format(record) + "\n")
            sys.stdout.flush()
        except Exception:  # noqa: BLE001 - 로그 때문에 요청이 실패하면 안 된다
            self.handleError(record)


def setup_logging() -> None:
    if any(isinstance(h, StdoutHandler) for h in logger.handlers):
        return
    handler = StdoutHandler()
    handler.setFormatter(JsonFormatter())
    logger.addHandler(handler)
    logger.setLevel(logging.INFO)
    logger.propagate = False


def log(message: str, **fields: object) -> None:
    logger.info(message, extra={"fields": fields})
