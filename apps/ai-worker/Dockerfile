FROM python:3.12-slim

# uv는 PyPI에서 설치한다(ghcr.io 장애 시에도 빌드되도록).
RUN pip install --no-cache-dir "uv==0.8.*"
ENV UV_COMPILE_BYTECODE=1 UV_LINK_MODE=copy UV_PROJECT_ENVIRONMENT=/opt/venv PATH="/opt/venv/bin:$PATH"

WORKDIR /srv
COPY pyproject.toml uv.lock ./
RUN uv sync --frozen --no-install-project

COPY . .

RUN useradd --uid 82 --no-create-home worker
USER worker

EXPOSE 8000
# 요청 로그는 app.main의 JSON 로그가 남긴다
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--no-access-log"]
