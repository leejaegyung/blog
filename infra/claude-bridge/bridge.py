#!/usr/bin/env python3
"""구독 연결기(claude-bridge).

Docker 안의 AI Worker가 이 Mac에 로그인된 구독으로 글을 쓰게 한다.
- provider "claude_code": Claude Code(Claude 구독) — `claude -p`(헤드리스)
- provider "codex": Codex CLI(ChatGPT 구독) — `codex exec`(헤드리스)
워커가 보낸 요청을 실행하고 결과를 JSON으로 돌려준다.

- API 키를 쓰지 않는다: 실행 환경에서 ANTHROPIC_API_KEY·OPENAI_API_KEY를 지워 구독 로그인이 쓰이게 한다.
- 도구는 끈다(사진이 있을 때만 그 사진을 읽는 Read). 작업 폴더는 요청마다 새 임시 폴더.
- 127.0.0.1에만 열고, 프로젝트 .env의 CLAUDE_BRIDGE_TOKEN이 맞는 요청만 받는다.
- 프롬프트·본문은 로그에 남기지 않는다(시간·모델·토큰·결과만).

실행: make claude-bridge   (또는 python3 infra/claude-bridge/bridge.py)
표준 라이브러리만 쓴다.
"""

import base64
import hmac
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MEDIA_EXT = {"image/jpeg": "jpg", "image/png": "png", "image/webp": "webp", "image/gif": "gif"}
MODEL_ALIASES = {"opus", "sonnet", "haiku"}


def load_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if path.exists():
        for line in path.read_text().splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                key, _, value = line.partition("=")
                values[key.strip()] = value.strip().strip('"').strip("'")
    return values


# 자동 실행(launchd)은 macOS가 데스크톱 폴더 접근을 막으므로 ~/.blog-ai 에 복사한 설정 파일을 읽는다
ENV_FILE = Path(os.environ.get("CLAUDE_BRIDGE_ENV_FILE") or ROOT / ".env")
ENV = {**load_env(ENV_FILE), **os.environ}
PORT = int(ENV.get("CLAUDE_BRIDGE_PORT", "8790"))
TOKEN = ENV.get("CLAUDE_BRIDGE_TOKEN", "")
PARALLEL = int(ENV.get("CLAUDE_BRIDGE_PARALLEL", "2"))
# 워커(290초)·Laravel(320초)보다 먼저 끝나야 뒤에서 포기하고 다시 부르는 일이 없다
TIMEOUT = int(ENV.get("CLAUDE_BRIDGE_TIMEOUT", "280"))
CLAUDE = ENV.get("CLAUDE_BIN") or shutil.which("claude") or str(Path.home() / ".local/bin/claude")
CODEX = ENV.get("CODEX_BIN") or shutil.which("codex") or "codex"
API_KEY_VARS = {"ANTHROPIC_API_KEY", "ANTHROPIC_AUTH_TOKEN", "OPENAI_API_KEY"}
CODEX_MODELS_CACHE = Path.home() / ".codex/models_cache.json"

# Claude Code는 별칭으로 최신 모델을 고른다
CLAUDE_MODELS = [
    {"id": "opus", "label": "Opus", "description": "가장 좋은 품질(구독 한도를 가장 많이 씀)"},
    {"id": "sonnet", "label": "Sonnet", "description": "품질과 속도의 균형"},
    {"id": "haiku", "label": "Haiku", "description": "빠르고 가벼움"},
]

slots = threading.BoundedSemaphore(PARALLEL)


def log(**fields: object) -> None:
    print(json.dumps({"time": time.strftime("%H:%M:%S"), **fields}, ensure_ascii=False), flush=True)


def valid_model(model: str) -> bool:
    return model in MODEL_ALIASES or (model.startswith("claude-") and model.replace("-", "").replace(".", "").isalnum())


def valid_codex_model(model: str) -> bool:
    return model.startswith(("gpt-", "o")) and model.replace("-", "").replace(".", "").isalnum()


def codex_models() -> list[dict]:
    """Codex가 로그인한 ChatGPT 계정 기준으로 받아 둔 모델 목록(숨김 모델 제외)."""
    try:
        data = json.loads(CODEX_MODELS_CACHE.read_text())
    except (OSError, ValueError):
        return []
    return [
        {"id": m["slug"], "label": m.get("display_name") or m["slug"], "description": m.get("description") or ""}
        for m in data.get("models", [])
        if m.get("slug") and m.get("visibility") == "list" and valid_codex_model(m["slug"])
    ]


def subscription_env() -> dict[str, str]:
    """구독 로그인을 쓰게 API 키 환경변수는 넘기지 않는다."""
    return {k: v for k, v in os.environ.items() if k not in API_KEY_VARS}


def write_images(work: str, images: list[dict]) -> list[str]:
    paths = []
    for index, image in enumerate(images, start=1):
        path = Path(work, f"photo-{index}.{MEDIA_EXT.get(image.get('media_type'), 'jpg')}")
        path.write_bytes(base64.b64decode(image["data"]))
        paths.append(str(path))
    return paths


def run_codex(body: dict) -> dict:
    """ChatGPT 구독(Codex CLI). 읽기 전용 샌드박스·임시 폴더에서 실행하고 JSONL 이벤트로 결과와 토큰을 읽는다."""
    model = str(body.get("model") or "gpt-5.5")
    if not valid_codex_model(model):
        return {"ok": False, "error": f"지원하지 않는 모델: {model}", "error_kind": "bad_request"}

    with tempfile.TemporaryDirectory(prefix="blog-ai-") as work:
        command = [
            CODEX, "exec", "--skip-git-repo-check", "--ephemeral", "--ignore-user-config", "--ignore-rules",
            "-s", "read-only", "-C", work, "-m", model, "--json",
            "-c", f"developer_instructions={json.dumps(str(body.get('system') or ''), ensure_ascii=False)}",
        ]
        if body.get("json_schema"):
            schema = Path(work, "schema.json")
            schema.write_text(json.dumps(body["json_schema"], ensure_ascii=False))
            command += ["--output-schema", str(schema)]
        for path in write_images(work, body.get("images") or []):
            command += ["-i", path]
        command += ["--", "-"]  # 프롬프트는 stdin으로

        try:
            done = subprocess.run(command, input=str(body.get("prompt") or ""), capture_output=True, text=True,
                                  cwd=work, env=subscription_env(), timeout=TIMEOUT)
        except subprocess.TimeoutExpired:
            return {"ok": False, "error": f"{TIMEOUT}초 안에 끝나지 않았습니다.", "error_kind": "timeout"}
        except FileNotFoundError:
            return {"ok": False, "error": f"codex 명령을 찾지 못했습니다: {CODEX}", "error_kind": "not_configured"}

    text, usage, failure = None, {}, None
    for line in done.stdout.splitlines():
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            continue
        kind = event.get("type")
        item = event.get("item") or {}
        if kind == "item.completed" and item.get("type") == "agent_message":
            text = item.get("text") or ""
        elif kind == "turn.completed":
            usage = event.get("usage") or {}
        elif kind == "turn.failed":
            failure = (event.get("error") or {}).get("message") or "실패"
        elif kind == "error" and not failure:
            failure = event.get("message")
    if text is None or (failure and not usage):
        message = (failure or done.stderr.strip()[-300:] or f"codex가 {done.returncode}로 끝났습니다.")[:300]
        return {"ok": False, "error": message, "model": model}

    structured = None
    if body.get("json_schema"):
        try:
            structured = json.loads(text)
        except json.JSONDecodeError:
            structured = None
    return {
        "ok": True,
        "text": text,
        "structured": structured,
        "model": model,
        "input_tokens": int(usage.get("input_tokens") or 0),
        "output_tokens": int(usage.get("output_tokens") or 0),
        "stop_reason": "end_turn",
    }


def run_claude(body: dict) -> dict:
    model = str(body.get("model") or "sonnet")
    if not valid_model(model):
        return {"ok": False, "error": f"지원하지 않는 모델: {model}", "error_kind": "bad_request"}

    with tempfile.TemporaryDirectory(prefix="blog-ai-") as work:
        prompt = str(body.get("prompt") or "")
        tools = ""
        images = body.get("images") or []
        if images:
            names = [Path(p).name for p in write_images(work, images)]
            tools = "Read"
            listing = "\n".join(f"{i}. ./{n}" for i, n in enumerate(names, start=1))
            prompt = f"첨부 사진(순서대로). Read 도구로 모두 열어 본 뒤 답하세요.\n{listing}\n\n{prompt}"

        command = [
            CLAUDE, "-p", "--safe-mode", "--no-session-persistence", "--output-format", "json",
            "--model", model, "--system-prompt", str(body.get("system") or ""), "--tools", tools,
        ]
        if tools:
            command += ["--allowedTools", tools]
        if body.get("json_schema"):
            command += ["--json-schema", json.dumps(body["json_schema"], ensure_ascii=False)]

        try:
            done = subprocess.run(command, input=prompt, capture_output=True, text=True, cwd=work,
                                  env=subscription_env(), timeout=TIMEOUT)
        except subprocess.TimeoutExpired:
            return {"ok": False, "error": f"{TIMEOUT}초 안에 끝나지 않았습니다.", "error_kind": "timeout"}
        except FileNotFoundError:
            return {"ok": False, "error": f"claude 명령을 찾지 못했습니다: {CLAUDE}", "error_kind": "not_configured"}

    try:
        result = json.loads(done.stdout)
    except json.JSONDecodeError:
        tail = (done.stderr or done.stdout).strip()[-300:]
        return {"ok": False, "error": tail or f"claude가 {done.returncode}로 끝났습니다.", "error_kind": "unavailable"}

    usage = result.get("usage") or {}
    used_model = next(iter(result.get("modelUsage") or {}), model)
    if result.get("is_error") or result.get("subtype") != "success":
        return {
            "ok": False,
            "error": str(result.get("result") or result.get("subtype"))[:300],
            "api_error_status": result.get("api_error_status"),
            "model": used_model,
        }
    return {
        "ok": True,
        "text": result.get("result") or "",
        "structured": result.get("structured_output"),
        "model": used_model,
        "input_tokens": int(usage.get("input_tokens") or 0) + int(usage.get("cache_read_input_tokens") or 0)
        + int(usage.get("cache_creation_input_tokens") or 0),
        "output_tokens": int(usage.get("output_tokens") or 0),
        "stop_reason": result.get("stop_reason"),
    }


class Handler(BaseHTTPRequestHandler):
    server_version = "claude-bridge"

    def log_message(self, *_: object) -> None:  # 기본 접근 로그(경로·IP)는 끈다
        pass

    def reply(self, status: int, data: dict) -> None:
        body = json.dumps(data, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def authorized(self) -> bool:
        given = self.headers.get("Authorization", "").removeprefix("Bearer ").strip()
        return bool(TOKEN) and hmac.compare_digest(given, TOKEN)

    def do_GET(self) -> None:
        if self.path not in ("/health", "/models"):
            return self.reply(404, {"error": "not found"})
        if not self.authorized():
            return self.reply(401, {"error": "토큰이 맞지 않습니다."})
        if self.path == "/models":
            return self.reply(200, {"claude_code": CLAUDE_MODELS, "codex": codex_models()})
        self.reply(200, {"ok": True, "claude": CLAUDE, "codex": CODEX, "parallel": PARALLEL})

    def do_POST(self) -> None:
        if self.path != "/generate":
            return self.reply(404, {"error": "not found"})
        if not self.authorized():
            return self.reply(401, {"error": "토큰이 맞지 않습니다."})
        try:
            body = json.loads(self.rfile.read(int(self.headers.get("Content-Length") or 0)))
        except (ValueError, json.JSONDecodeError):
            return self.reply(400, {"error": "JSON이 아닙니다."})

        started = time.perf_counter()
        provider = body.get("provider") or "claude_code"
        runner = {"claude_code": run_claude, "codex": run_codex}.get(provider)
        if runner is None:
            return self.reply(400, {"error": f"모르는 공급자: {provider}"})
        with slots:  # 구독 한도를 아끼려고 동시에 PARALLEL개까지만 실행한다
            result = runner(body)
        log(event="generate", provider=provider, model=result.get("model", body.get("model")), ok=result["ok"],
            images=len(body.get("images") or []), seconds=round(time.perf_counter() - started, 1),
            input_tokens=result.get("input_tokens"), output_tokens=result.get("output_tokens"))
        self.reply(200, result)


def main() -> None:
    if not TOKEN:
        sys.exit("프로젝트 .env에 CLAUDE_BRIDGE_TOKEN이 없습니다. `make claude-bridge`로 실행하면 만들어 줍니다.")
    server = ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    log(event="start", port=PORT, claude=CLAUDE, codex=CODEX, parallel=PARALLEL)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
