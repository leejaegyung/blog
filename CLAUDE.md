# Blog AI

키워드 + 사용자가 직접 찍은 사진 + 사실 몇 줄로 네이버 블로그 초안을 만드는 1인용 서비스.
전체 기획은 `AI_블로그_자동작성_서비스_Docker_SQLite_통합기획.md`, 진행 상황은 `docs/progress.md`.

## 구조

```
apps/api        Laravel 12 (PHP 8.4, php-fpm) — API, 인증(Sanctum SPA 쿠키), Queue Job
apps/web        Vue 3 + TS + Vite + Pinia + Router + Tailwind v4 — NGINX가 정적 서빙
apps/ai-worker  FastAPI (Python 3.12, uv) — 파싱/NLP/Vision/LLM/Quality Gate
infra/nginx     SPA 서빙 + /api·/sanctum·/up → app:9000 (fastcgi)
```

컨테이너: `nginx`(127.0.0.1:8080) · `app` · `queue` · `scheduler` · `ai-worker`. SQLite 파일은
`sqlite-data` 볼륨의 `/data/database/blog-ai.sqlite`를 app/queue/scheduler가 공유한다.

## 명령

로컬에 PHP·Python이 없다. PHP/Python 명령은 전부 `Makefile`을 통해 컨테이너에서 실행한다.

- `make up` / `make down` / `make logs`
- `make test` — api(PHPUnit) + worker(pytest) + web(vitest, type-check, lint)
- `make check` — **완료 선언 전 필수.** test → 재빌드 → `/api/health`가 `ok`인지 확인
- `make artisan c="make:model Post -m"`, `make composer c="require ..."`
- AI Worker 의존성 추가 후: `docker run --rm -v "$PWD/apps/ai-worker":/srv -w /srv ghcr.io/astral-sh/uv:0.8-python3.12-bookworm-slim uv add <pkg>`
- 로그인 계정 생성/비밀번호 재설정: `docker compose exec app su-exec www-data php artisan app:create-user <아이디|이메일>`
  (기본 계정은 아이디 `admin`. 비밀번호는 코드·문서에 적지 않는다)
- 빌드가 `load metadata ... DeadlineExceeded`로 실패하면(Docker Desktop 엔진 프록시 장애): `make base-images` 후 다시 빌드
- 운영: `php artisan app:backup`(수동 백업, 결과는 호스트 `data/backups/`), `app:recover-stuck`, 관리 화면 `/admin`
- 로그: `docker compose logs queue ai-worker | grep <trace_id>` — 한 요청의 Laravel·큐·워커 로그가 같은 trace_id를 가진다
- 프론트 개발 서버: `cd apps/web && npm run dev` (/api는 :8080으로 프록시)

## 확정된 결정 (기획서와 다를 때 이쪽이 우선)

- **Redis 없음.** Queue/Cache/Session 모두 `database` 드라이버. 기획서 8장의 Redis 항목은 무시.
- **벡터 DB 없음.** 임베딩은 JSON/파일로 저장하고 AI Worker에서 cosine similarity 계산.
- SQLite는 WAL + `busy_timeout=5000` + `transaction_mode=IMMEDIATE` (`config/database.php`).
- 마이그레이션은 `app` 컨테이너 시작 시에만 실행(`RUN_MIGRATIONS=true`). queue/scheduler는 실행하지 않음.
- 운영 이미지는 코드를 복사해 굽는다. 코드 변경 후 확인하려면 `make up`으로 재빌드.
- **자동 로그인**(`AUTH_AUTO_LOGIN=true`, 계정 `AUTH_AUTO_LOGIN_USER`): 로그인 화면 없이 브라우저 요청을 그 계정으로 로그인시킨다
  (`AutoLogin` 미들웨어, 인증 미들웨어보다 먼저 실행되도록 우선순위 지정). **그래서 포트는 `127.0.0.1`에만 연다.**
  휴대폰·다른 기기는 Tailscale로만: `tailscale serve --bg --http=8080 http://127.0.0.1:8080`(내 tailnet 기기만, 설정 유지)로
  `http://<기기이름>:8080`에 열고, 그 주소를 `.env`의 `SANCTUM_STATEFUL_DOMAINS`에 더한다. IP 주소로는 안 열린다(serve는 이름으로만 응답).
  끄기: `tailscale serve --http=8080 off`
  **http 주소에서는 브라우저가 클립보드(특히 사진 복사)를 막는다.** 6단계 붙여넣기를 쓰려면 이 Mac은 `localhost:8080`, 다른 기기는 https가 필요하다:
  Tailscale 관리 화면 DNS에서 HTTPS 인증서를 켜고 `tailscale serve --bg --https=443 http://127.0.0.1:8080` → `https://<기기>.<tailnet>.ts.net`을 SANCTUM_STATEFUL_DOMAINS에 더한다
  서버·공유 네트워크에 올릴 때는 `AUTH_AUTO_LOGIN=false`로 바꾸고 포트 바인딩을 다시 검토한다.
- 인증은 Sanctum SPA 쿠키 세션(토큰 아님). 새 API는 `auth:sanctum` 그룹 안에 두고, 소유권은 Policy로 검사한다.
- LLM은 OpenAI(GPT)와 Anthropic(Claude) 둘 다 지원한다. 모든 호출은 `app/llm/router.py`의 `LLMRouter`를 거친다
  (어댑터를 직접 부르지 않는다). 재시도는 SDK에 맡기고 라우터는 공급자 간 fallback만 한다. `temperature`는 쓰지 않는다.
- 연결 확인: `docker compose exec app su-exec www-data php artisan app:llm-ping [--target=openai:gpt-5.5]`
- **구독으로 생성**: 공급자 `claude_code`(Claude Code, 모델 opus·sonnet·haiku)·`codex`(Codex CLI·ChatGPT 구독, gpt-5.5 등, 로그인 `codex login`). 호스트 Mac의 `infra/claude-bridge/bridge.py`가
  `claude -p`(헤드리스, 구독 로그인, ANTHROPIC_API_KEY 제거, 도구 끔)를 실행하고 워커는 `host.docker.internal:8790`으로 부른다.
  `make claude-bridge-install`(로그인 시 자동 실행, ~/.blog-ai 에 복사해 실행 — macOS가 데스크톱 폴더 접근을 막음), 로그 `~/.blog-ai/claude-bridge.log`,
  해제 `make claude-bridge-uninstall`. bridge.py를 고치면 install을 다시 실행. 토큰 `CLAUDE_BRIDGE_TOKEN`(.env)
- 사진 처리(리사이즈·재인코딩·EXIF·썸네일)는 AI Worker가 한다. Laravel은 검증·저장 위치·권한만 맡는다.
  파일 경로는 항상 업로드 볼륨 기준 상대 경로로 주고받는다(`app/storage.py`의 `resolve_upload_path`).
- 컨테이너 공유 파일 소유자는 uid 82(`www-data`). AI Worker도 uid 82로 실행한다.

## 제품 규칙 (코드로 강제해야 하는 것)

- **참고 글 원문은 저장하지도, LLM에 넣지도 않는다.** 워커가 읽는 즉시 `document_features.features_json`(구조·통계)만
  남긴다. 이후 단계(키워드 분석·글 생성)는 이 특징만 입력으로 쓴다. 특징에 원문 문장을 넣지 않는다.
- **네이버 도메인은 서버가 가져오지 않는다**(robots.txt의 AI 목적 봇 금지). 차단 목록은 워커 `BLOCKED_HOST_SUFFIXES`와
  Laravel `App\Support\ReferenceUrl`에 있고 둘을 같게 유지한다. User-Agent를 브라우저로 위장하지 않는다.
- 가격·주소·메뉴 같은 구체적 사실은 사용자 입력(`post_facts`)에 있을 때만 확정 표현으로 쓴다. 사진만 보고 확정하지 않는다.
- 크롤링(목록 순회·자동 수집)과 네이버 자동 게시는 구현하지 않는다(약관 검토 전). 게시는 Copy/Export가 기본이다.
- 로그에 프롬프트·사용자 사실·본문·LLM 오류 원문을 남기지 않는다. 메타데이터(용도·모델·결과·토큰·지연)만.
- 모든 LLM 호출(실패한 fallback 시도 포함)은 provider / model / prompt_version / tokens / latency를 `generations`에 기록한다.
  **금액(cost)은 계산하지 않는다** — 단가표를 두지 않는다.
- 프롬프트는 코드에 하드코딩하지 않고 버전을 붙여 관리한다(`prompts/` 또는 `prompt_templates`).
- API 키를 프론트엔드에 전달하지 않는다. 네이버 비밀번호와 쿠키는 저장하지 않는다.

## 작업 방식

- 기능 하나를 끝낼 때마다 테스트를 추가하고 `make check`를 통과시킨 뒤 `docs/progress.md`를 갱신한다.
- 세션을 시작할 때 `docs/progress.md`의 "다음 작업"부터 이어서 진행한다.
