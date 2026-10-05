import re
import time

from fastapi import FastAPI, Request

from app.api import health, images, keywords, llm, places, posts, references
from app.logging_setup import log, setup_logging, trace_id

setup_logging()

app = FastAPI(title="Blog AI Worker")
app.include_router(health.router)
app.include_router(images.router)
app.include_router(llm.router)
app.include_router(references.router)
app.include_router(keywords.router)
app.include_router(posts.router)
app.include_router(places.router)

TRACE_ID = re.compile(r"^[A-Za-z0-9-]{8,64}$")


@app.middleware("http")
async def trace_requests(request: Request, call_next):
    """Laravel이 보낸 X-Request-Id를 이어받아 이 요청의 모든 로그에 붙인다."""
    incoming = request.headers.get("x-request-id", "")
    token = trace_id.set(incoming if TRACE_ID.match(incoming) else None)
    started = time.perf_counter()
    status = 500
    try:
        response = await call_next(request)
        status = response.status_code
        return response
    finally:
        if request.url.path != "/health":
            log("request", method=request.method, path=request.url.path, status=status,
                latency_ms=int((time.perf_counter() - started) * 1000))
        trace_id.reset(token)
