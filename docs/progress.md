# 진행 상황

기획서 36장 "개발 시작 순서" 기준. 완료 조건: 테스트 추가 + `make check` 통과.

| Day | 범위 | 상태 |
|---|---|---|
| 1 | Repository / Docker Compose / Laravel / Vue / SQLite | ✅ 2026-09-27 |
| 2 | Auth / Project / Post DB | ✅ 2026-09-27 |
| 3 | Image Upload / Storage | ✅ 2026-09-27 |
| 4 | FastAPI AI Worker (LLM Adapter) | ✅ 2026-09-27 |
| 5 | Reference Parser | ✅ 2026-09-27 |
| 6 | Feature Extractor | ✅ 2026-09-27 |
| 7 | Keyword Intelligence | ✅ 2026-09-27 |
| 8 | Writing Plan | ✅ 2026-09-27 (실제 LLM 결과 미확인) |
| 9 | Draft Generator | ✅ 2026-09-27 (실제 LLM 결과 미확인) |
| 10 | Editor (TipTap) | ✅ 2026-09-27 (브라우저 수동 확인 전) |
| 11 | Vision | ✅ 2026-09-27 (실제 LLM 결과 미확인) |
| 12 | Quality Gate | ✅ 2026-09-27 |
| 13 | Export / Preview | ✅ 2026-09-27 |
| 14 | Queue / Retry / Logging | ✅ 2026-09-27 |

## Day 1 결과

- 5개 컨테이너 기동, `GET /api/health` → `{"status":"ok","checks":{"database":true,"ai_worker":true}}`
- SQLite WAL 모드 확인, `queue` 컨테이너가 database 드라이버로 Job 처리 확인
- Sanctum SPA 쿠키 인증 준비(`statefulApi`, `/sanctum/csrf-cookie` 204)
- 테스트: api 4개, worker 1개, web 1개

## Day 2 결과

- 로그인: Sanctum SPA 쿠키 세션. `POST /api/login`(분당 5회 제한) · `POST /api/logout` · `GET /api/user`
- 계정은 명령으로 만든다: `docker compose exec app su-exec www-data php artisan app:create-user <email>`
- 마이그레이션 11개: 기획서 11·14장 테이블 전부 + 아래 추가 컬럼
  - `reference_documents.parse_status/error_message` (Day 5 파서 상태)
  - `keyword_analyses.source_hash/expires_at` (21장 캐시 키·TTL)
  - `generations.keyword_project_id/purpose/provider/status` (분석 호출 기록, GPT·Claude 구분)
  - `posts.tone/target_length`, `post_images` 메타데이터, `prompt_templates.provider`
- `/api/projects` CRUD. 키워드는 공백 정규화 후 사용자별 unique. 다른 사용자 리소스는 403.
  프로젝트를 삭제해도 글은 남는다(`keyword_project_id` → null).
- Vue: 로그인 화면, 인증 스토어, 라우터 가드(`authGuard`), 프로젝트 목록·생성·상세·삭제
- NGINX 경유 실제 흐름 확인: csrf-cookie → login → 프로젝트 생성/목록 → logout → 401
- 테스트: api 18개, worker 1개, web 5개
- 제외: `users.plan`(1인용이라 불필요)

## Day 3 결과

- 사진 처리는 AI Worker(Pillow + pillow-heif)가 맡는다: `POST /images/process`
  - EXIF 방향 보정 → 긴 변 2048px 리사이즈 → JPEG(q85) 재인코딩 → 400px 썸네일
  - EXIF 기본 제거. 유지 옵션이어도 **GPS는 항상 삭제**. 촬영 시각은 `taken_at`으로 돌려준다
  - 8천만 화소 초과(압축 폭탄) 거부, 업로드 볼륨 밖 경로 거부(400)
- Laravel: `POST /api/posts/{id}/images`(한 요청에 1장), `DELETE .../{image}`, `PATCH .../order`, `GET .../{image}/file[?variant=thumb]`
  - MIME은 확장자가 아니라 파일 내용으로 검사. 글당 30장, 장당 20MB
  - 원본은 `tmp/`에 잠깐 두고 처리 후 삭제. 결과는 `users/{user}/posts/{post}/{uuid}.jpg`
  - 파일은 로그인한 소유자만 받을 수 있다(쿠키 인증 라우트, Signed URL 대신)
  - 워커 422 → 검증 오류, 워커 장애 → 503
- 글 API: `GET/POST /api/posts`, `GET/PATCH /api/posts/{id}`. 사실 정보(`facts`)는 목록 통째로 교체
- 마이그레이션 추가: `post_images.thumb_key`, `post_images.taken_at`
- Vue: 프로젝트 상세에서 "새 글 작성" → 글 편집 화면
  - 사진: 드래그 앤 드롭/파일 선택, 동시 3장 업로드, 진행률, 파일별 오류, EXIF 제거 체크박스
  - 사진 순서: 드래그 + ←/→ 버튼(키보드 접근), 삭제. 실패 시 이전 상태로 되돌림
  - 사실 정보 입력(추천 항목 datalist), 말투·길이 선택, 사진 촬영일 → "방문 날짜" 추가 제안
- 실제 스택 확인: 4032×3024 JPEG(orientation 6, GPS) → 1536×2048·EXIF 0개, HEIC 변환, PHP 파일 위장 422,
  미인증 파일 요청 401, 순서 변경·삭제 후 디스크에 파일이 남지 않음
- 테스트: api 38개, worker 9개, web 12개
- 브라우저에서 화면을 직접 눌러 보는 확인은 아직 안 함(컴포넌트 테스트로만 검증)

## Day 4 결과

- `apps/ai-worker/app/llm/`: 공통 `LLMRequest`/`LLMResult` + `AnthropicAdapter`(anthropic 1.8) + `OpenAIAdapter`(openai 3.19, Responses API)
  - 텍스트, 이미지 입력(base64), 구조화 출력(`output_model`=Pydantic → Anthropic `messages.parse` / OpenAI `responses.parse`)
  - 오류를 공통 `LLMError.kind`로 분류: not_configured · rate_limited · unavailable · bad_request · auth · refused · truncated · invalid_output
  - `temperature`는 보내지 않는다(Claude Opus 5 등 최신 모델이 400으로 거부)
- `LLMRouter`: `LLM_ROUTE` 순서대로 시도. 429/5xx/연결 오류의 지수 백오프는 SDK가 `max_retries=3`으로 처리하고,
  그래도 실패하거나 거절·잘림·키 없음이면 다음 공급자로 fallback. **성공·실패 시도 전부**를 `generations` 메타로 돌려준다
- 기본 순서: `anthropic:claude-opus-5` → `openai:gpt-5.5`. 호출별로 `targets`를 넘겨 바꿀 수 있다
- `POST /llm/ping` + Laravel `php artisan app:llm-ping [--target=openai:gpt-5.5]` — 실제 키 연결 확인용, 호출 기록 저장
- Laravel `GenerationRecorder`: 워커 메타 → `generations` 행. `generations.cost`는 nullable로 바꾸고 기록하지 않는다
- 버그 수정: `app` 컨테이너만 재생성되면 NGINX가 예전 IP로 붙어 502 → Docker DNS(127.0.0.11)로 매번 재조회.
  `make smoke`도 최대 30초 재시도
- 테스트: api 41개, worker 33개(어댑터는 SDK를 실제로 거치고 HTTP만 mock), web 12개
- **실제 API 키로 호출한 확인은 아직 안 함** (키 없음 → 두 공급자 모두 not_configured로 기록되는 것까지 확인)

## Day 5 결과

- 참고자료 입력: **URL 추가** 또는 **본문 붙여넣기**, 프로젝트당 50개. URL은 `#` 이후를 떼고 중복이면 건너뜀
  - 네이버 URL(`naver.com`·`naver.me`·`naver.net`)은 등록만 하고 `needs_text` 상태 → 사용자가 본문을 붙여넣으면 분석
  - 붙여넣기에서 `[사진]` 한 줄 = 사진 위치, `# 제목` = 소제목, `- 항목` = 목록
- AI Worker `POST /references/parse` → 공통 구조(`blocks`: heading·paragraph·image·list·quote·table 순서 보존)를
  `references/{project}/{id}.json`에 저장. 원문 HTML은 저장하지 않음. 붙여넣은 `.txt`는 파싱 성공 후 삭제
- URL 가져오기(`app/references/fetcher.py`)
  - 사설·내부 주소 차단(SSRF), 리다이렉트마다 재검사(최대 5회) → 단축 URL로 네이버·내부망 우회 불가
  - robots.txt 준수(RFC 9309 파서 `protego`), 정직한 User-Agent `BlogAIReferenceFetcher/0.1`, 같은 호스트 1초 간격
  - 5MB 제한, HTML만, 15초 타임아웃. 4xx·robots·네이버 → 영구 실패(422), 5xx·타임아웃 → 재시도(502)
- Laravel: `GET/POST /api/projects/{id}/references`, `POST /api/references/{id}/text`, `POST .../parse`(재시도),
  `DELETE /api/references/{id}`. `ParseReferenceJob`(3회, 30s·120s 백오프). 같은 프로젝트에 내용이 같은 글은 `duplicate`
- `document_features`에 기본 수치(글자·문단·사진·소제목 수)만 먼저 기록(`analyzer_version=parse-1`)
- Vue: 프로젝트 상세에 참고자료 패널(URL/붙여넣기 탭, 상태, 수치, 본문 붙여넣기, 다시 시도, 삭제, 분석 중 2초 폴링)
- 실제 스택 확인: 위키백과 문서 → 소제목 18·사진 12·표 2 추출, robots 금지 경로 거부, 404 영구 실패,
  169.254.169.254·`app:9000` 차단, 네이버 URL → 붙여넣기 → 사진 3 인식. 확인 데이터 정리함
- 버그 수정
  - 표준 `urllib.robotparser`는 User-Agent를 부분 문자열로 비교해 위키백과의 `User-agent: Fetch / Disallow: /`가
    우리(`...Fetcher`)에게 적용됨 → `protego`로 교체
  - `ghcr.io` 장애로 워커 빌드 실패 → uv를 PyPI에서 설치하도록 Dockerfile 변경
- 테스트: api 55개, worker 71개, web 16개

## Day 6 결과

- **본문은 어디에도 저장하지 않는다.** 워커 `POST /references/parse`가 가져오기 → 구조 추출 → 특징 추출을 한 번에 하고
  특징만 돌려준다. 붙여넣은 `.txt`도 성공 후 삭제. `reference_documents.raw_storage_key`는 항상 null
- `app/analyzers/features.py` (`features-1`) → `document_features.features_json`
  - 수치: 글자(공백 포함/제외)·문장·문단·소제목·사진·목록·인용·표·숫자·질문 수
  - 제목: 길이, 키워드 위치(start/middle/end/none), 숫자·괄호·?·! 여부, 제목 명사 ("- 사이트명" 꼬리 제거)
  - 키워드: 전체 일치 횟수, 토큰별 횟수, 첫 등장 위치 비율, 첫 문단 포함 여부, 1000자당 빈도
  - `layout`: 블록 순서 코드(H·P·I·L·Q·T) — 원문 없이 구성 패턴을 비교하는 용도
  - 사진: 도입부 사진 수, 연속 묶음 수·최대 크기, 묶음 사이 평균 문단 수, 1000자당 사진 수, 처음/끝 위치
  - 섹션 수·평균 길이, 자주 나오는 명사 30개(Kiwi, 불용어·영문 잡음 제외), 소제목 명사
  - 정보 항목(slots): 가격·주소·전화·영업시간·주차·예약·웨이팅·메뉴·장점·단점·추천
  - 도입부 유형(인사/질문/요약/이야기), 마무리(총평·추천·공감 요청)
  - 결과에 원문 문장이 들어가지 않는지 테스트로 확인
- 재분석 규칙: 서버가 다시 가져올 수 있는 URL만 "다시 시도". 네이버·붙여넣기 글은 본문을 다시 붙여넣어야 함
- 알려진 한계: 프로젝트 키워드를 바꾸면 키워드 관련 특징(빈도·위치)은 예전 키워드 기준으로 남는다(본문이 없어 재계산 불가)
- 실제 스택 확인: 위키백과 + 붙여넣은 후기 → 특징 저장, 디스크에 본문 파일 0개
- 테스트: api 56개, worker 81개, web 17개

## Day 7 결과

- 워커 `POST /keywords/analyze` = 두 단계
  1. `app/analyzers/aggregate.py`(`stats-1`, 코드): 글자·소제목·문단·사진 수 분포(중앙값·p25·p75), 사진으로 시작하는 비율,
     도입부 사진·최대 묶음·묶음 사이 문단, 정보 항목별 비율, 공통 소제목 주제·단어(문서 빈도, 참고자료 3개 이상이면
     2개 글 이상에 나온 것만, 키워드 자체 제외), 시작 구성 패턴, 제목 길이·키워드 위치·괄호/숫자 비율, 도입·마무리 비율
  2. `app/generators/keyword_insight.py` + `prompts/keyword-analysis-v1.md`(LLM, 구조화 출력): 입력은 키워드·카테고리·통계뿐.
     검색 의도(대표 + 분포), 꼭 답할 질문, 추천 목차(소제목·목적·사진 힌트), 제목 가이드, 연관 표현, 작성 팁
  - **LLM이 모두 실패해도 통계는 반환·저장**(`insight=null`, `insight_error`). 참고자료 0개면 키워드만으로 해석
- Laravel `POST /api/projects/{id}/analyze`(202, `AnalyzeKeywordJob`) · `GET /api/projects/{id}/analysis`
  - 캐시: 키워드·카테고리·parsed 참고자료 content_hash 목록의 해시 + 7일 TTL + AI 해석이 있을 때만 재사용. `force`로 무시
  - 분석 뒤 참고자료·키워드가 바뀌면 `stale: true`
  - 프로젝트 상태 draft → analyzing → analyzed / failed, `generations`에 purpose `keyword_analysis`로 기록
  - 마이그레이션: `keyword_analyses.stats_json · insight_json · insight_error · prompt_version`
- Vue: 프로젝트 상세에 분석 대시보드(기획서 16장) — "등록한 참고자료 N개의 분포, 순위 예측 아님" 명시,
  AI 해석이 없으면 통계만 + API 키 안내, 분석 중 3초 폴링, stale 안내
- 실제 스택 확인(키 없음): 참고자료 3개 → 통계 저장·표시, 두 공급자 not_configured 기록, 재분석 시 캐시 미사용
- 테스트: api 64개, worker 90개(프롬프트에 참고 글 문장이 없는지 포함), web 21개
- **실제 LLM 해석 품질은 아직 확인 못 함** (프롬프트 튜닝 대상)
  - 2026-09-27: `ANTHROPIC_API_KEY` 설정됨. 호출 결과 "credit balance is too low" → 계정 크레딧 충전 필요.
    OpenAI 키는 아직 없음
- 오류 분류 추가: 크레딧·한도 소진(Anthropic 400 credit balance, OpenAI 429 insufficient_quota) → `billing`.
  대시보드가 "크레딧 부족" 안내를 따로 보여준다
- 대시보드는 컴포넌트 테스트로만 확인, 브라우저 화면 확인은 아직

## Day 8 결과

- 워커 `POST /posts/plan` + `prompts/writing-plan-v1.md` → `WritingPlan`(구조화 출력):
  제목 후보 5, 검색 의도, 목차(소제목·목적·핵심 메모·사용할 사실 key·배치할 사진 id), 주/보조 키워드,
  반드시 넣을 사실 key, 쓰지 않을 내용
- **LLM 결과를 코드로 재검증**(`check_plan`): 입력에 없는 사실 key·없는 사진 id 제거, 사진 중복 배치 제거,
  사용자 사실은 전부 required로, 중복 제목 제거. 고친 내용은 `corrections`, 남은 것은 `unused_fact_keys`·`unplaced_image_ids`
- **코드가 만드는 단정 금지**: 참고자료 50% 이상이 다루는 정보(가격·위치·연락처·영업시간·주차·예약·웨이팅·메뉴)를
  사용자가 입력하지 않았으면 "…는 입력되지 않았으므로 단정하지 말 것"을 `forbidden_claims` 앞에 추가
- Laravel: `POST /api/posts/{id}/plan`(사실 1개 이상 필요, 202, `GeneratePlanJob`), `PUT /api/posts/{id}/plan`
  (제목 선택·목차 수정 저장, 사실 key·사진 id는 이 글 것만, 사진 중복 금지, 미사용 목록 재계산)
  - posts: `plan_json`, `plan_error`, 상태 `planning`·`planned` 추가. 제목이 비어 있으면 첫 후보로 채움
  - 계획이 가리키는 사실·사진이 사라지면 `plan_stale: true`
- Vue: 편집 화면에 "글 계획" — 만들기 전에 입력 내용을 자동 저장, 계획 중 3초 폴링, 제목 후보 선택,
  목차 소제목·내용 편집·순서 변경·삭제, 섹션별 사진(올린 순서 번호), 미배치 사진·미사용 사실, 쓰지 않을 내용
- 실제 스택 확인: 요청 → 큐 → Claude(billing) → GPT(not_configured) → `failed` + 사유 표시
- 테스트: api 70개, worker 98개, web 24개
- 한계: 사진은 아직 순서·촬영 시각만 전달(내용은 Day 11 Vision 이후). 섹션 간 사진 옮기기 UI는 없음(Day 10 편집기에서)

## Day 9 결과

- 워커 `POST /posts/draft` + `prompts/blog-draft-v1.md` → `Draft`(구조화 출력: 도입·섹션별 블록·마무리·태그)
  - 블록: paragraph·image(`image_id`)·list·quote. 말투 4종별 문체 지시, 모바일 가독성(1~3문장 문단), 사실만 단정
  - 입력: 선택한 제목, 사실 값, 계획(목차·키워드·required·forbidden), 사진 id·순서. 참고 글 원문 없음
- **코드 검증**(`check_draft`) → 편집기용 평평한 블록 목록(`heading` 포함) + 경고
  - 입력 사실에 없는 **가격·시간·전화번호·주소** 표현 탐지(Hallucination Gate 1차) → `unsupported_specific`
  - 숫자가 든 사실(예: 19,000원)이 본문에 그대로 없으면 `fact_missing`
  - 목표 길이의 70~140% 밖이면 `length`, 잘못된·중복 사진 제거, 빠진 사진 경고
  - 태그 정리(# 제거·공백 제거·중복 제거·최대 15개), 키워드 횟수
  - 비스트리밍 요청은 `max_tokens` 16000 유지(SDK 타임아웃 가드)
- Laravel `POST /api/posts/{id}/generate`(202, `GenerateDraftJob`): 계획 필수, 계획이 stale이면 거부, 중복 요청 무시
  - posts: `content_json {blocks, tags}`, `content_text`, `draft_meta_json {char_count, target_length, keyword_count, warnings}`,
    `draft_error`, `generation_version`. 상태 generating → review. 사용자가 고른 제목 유지
- Vue: 편집 화면 "초안" — 경고(환각 의심을 맨 위), 글자 수/목표·키워드 횟수, 네이버 글 폭(693px)에 가까운 미리보기,
  PC/모바일(390px) 전환, 태그 표시
- 테스트: api 76개, worker 104개, web 27개
- Docker 빌드 장애: Docker Desktop 엔진이 프록시(`http.docker.internal:3128`)를 통해 레지스트리에 못 붙음
  (재시작해도 동일, 컨테이너 네트워크·호스트는 정상). 로컬에 없는 베이스 이미지만 조회가 필요해 실패하는 것 →
  `make base-images`(컨테이너에서 레지스트리 API로 받아 `docker load`)로 우회 후 `make check` 통과

## Day 10 결과

- TipTap 3.31.3(`@tiptap/vue-3`·`starter-kit`·`pm` 버전 고정). 평문 블록 저장 형식에 맞춰 글자 서식·코드·링크·번호 목록은 끔,
  소제목은 h2만
- `src/editor/convert.ts`: `content_json.blocks` ↔ TipTap 문서 왕복 변환(줄바꿈 = hardBreak, 빈 블록 제거),
  `blocksText`는 서버 `PostContent::text`·워커 `render_text`와 같은 규칙
- `src/editor/postImage.ts`: 사진 블록 노드(`imageId` 속성만 저장, URL은 화면에서 조회), Vue 노드 뷰, 드래그로 이동,
  "사진 빼기", `insertPostImage` 명령. 글에 없는 사진 목록에서 눌러 커서 위치에 삽입
- 편집기: 소제목·목록·인용, 되돌리기/다시 실행, 1.5초 디바운스 자동 저장(저장 상태 표시, 실패 시 다시 저장, 화면을
  떠날 때 남은 변경 저장), 태그 편집
- AI 문단 다시 쓰기(짧게·길게·자연스럽게·다시 쓰기): 커서가 있는 최상위 문단 + 앞뒤 문단을 문맥으로
  `POST /api/posts/{id}/rewrite`(동기) → 워커 `/posts/rewrite` + `prompts/paragraph-rewrite-v1.md`.
  원문·사실에 없던 가격·시간·전화·주소가 새로 생기면 경고. 기다리는 동안 문단이 바뀌면 적용하지 않음. 되돌리기로 취소 가능
- 서버: `PATCH /api/posts/{id}`에 `content` 저장(블록 타입·사진 소유 검증, 텍스트·글자 수·키워드 횟수 재계산,
  초안 경고는 유지), 초안 생성 시 `content_original_json` 보관
- "AI 초안과 비교" 탭: 단어 단위 Diff + 수정률(기획서 29장 User Edit Distance)
- 테스트: api 81개, worker 107개, web 38개(편집기 자동 저장·사진 삽입·문단 교체·되돌리기 포함)
- **실제 브라우저에서 편집기를 눌러 보는 확인은 아직** (jsdom 테스트로만 검증)

## Day 11 결과

- 워커 `POST /images/analyze` + `prompts/photo-analysis-v1.md`: 사진을 긴 변 1024px로 줄여 6장씩 한 번에 LLM 이미지 입력
  → 사진별 종류(food·drink·exterior·interior·menu_board·product·package·view·person·receipt·other), 추정 표현 설명
  ("~로 보이는"), 사용 적합, 품질 점수, 추천 섹션, 캡션 힌트, **개인정보 표시**(얼굴·번호판·영수증·연락처·타인 정보)
  - 배치 하나가 실패해도 나머지 결과는 저장. 입력에 없는 id·중복 결과 제거, 점수 0~1로 자름
- Laravel `POST /api/posts/{id}/images/analyze`(분석 안 한 사진만, `force`면 전부, 분석 중인 사진 제외) → `AnalyzeImagesJob`
  - `post_images.vision_json`, `vision_status`(pending·done·failed), `vision_error`. `generations` purpose `vision`
  - **비용 때문에 업로드 시 자동 분석하지 않음**(버튼으로 실행)
- 계획·초안에 사진 분석 전달: 프롬프트 `writing-plan-v2`(종류·추천 섹션으로 배치, 사용 비추천 사진 제외,
  설명을 사실로 쓰지 않음), `blog-draft-v2`(설명은 추정 표현 유지). v1 파일은 그대로 둠
- Vue: 사진 칸에 "사진 분석 (N장)" 버튼·3초 폴링, 사진마다 종류·설명·사용 비추천·개인정보 확인 표시,
  개인정보가 보이는 사진 수 경고
- 실제 스택 확인: 업로드 → 분석 요청 → Claude(billing)·GPT(not_configured) → `failed` 표시
- 테스트: api 86개, worker 111개, web 39개

## Day 12 결과

- 워커 `POST /posts/quality-check`(`app/quality/gate.py`, `quality-1`): **코드 규칙만** 사용(빠르고 결정적, 비용 없음)
  - error: 입력에 없는 가격·시간·전화·주소, 개인 휴대전화 번호, 협찬 사실이 있는데 본문에 표기 없음, 개인정보 사진 사용
  - warning: 사실 누락(숫자는 그대로, 문장형은 핵심 명사 절반 이상), 계획의 단정 금지 항목 언급(예: 주차), 입력하지 않은
    "내돈내산" 주장, 과장 표현, 같은 문장 반복, 키워드 없음·과다(500자 이상 글만, 참고자료 p75의 2배 또는 1000자당 8회),
    제목에 키워드 없음, 사진 없음
  - info: 문장 끝 반복, 긴 문단(4문장 초과), 길이, 쓰지 않은 사진, 긴 제목
  - 문제마다 위치(블록 번호·본문 조각). 점수 100점(기획서 19장 7개 항목), "내부 점수·순위 보장 아님"
- Laravel `POST /api/posts/{id}/quality-check`(동기) + `QualityGate` 서비스, 초안 생성 직후 자동 검사(실패해도 초안 유지)
  - posts: `quality_json`, `quality_checked_at`. 검사 뒤 제목·본문·사실·사진 분석이 바뀌면 `quality_stale`
- Vue: "게시 전 검사" — 점수·항목별 막대·심각도별 문제(꼭 고치기/확인 권장/참고), "위치 보기"로 편집 탭 전환 후
  해당 문단 선택(사진은 노드 선택)·스크롤
- 실제 스택 확인: 문제를 섞은 글 → 지어낸 가격·시간, 010 번호, "내돈내산", 과장, 반복 문장을 위치와 함께 검출(70점)
- 테스트: api 90개, worker 121개, web 43개
- 미룸: LLM 문장 단위 근거 분류(기획서 7.2 VERIFIED_USER_FACT 등) — 크레딧 확보 후 선택 기능으로

## Day 13 결과

- `App\Publishing\Publisher` 인터페이스(기획서 17장: `validate`·`publish`) + `ManualExportPublisher`(MVP 기본 게시 방식).
  네이버 자동 게시는 구현하지 않음
- `PostExporter`: 붙여넣기용 HTML(h2·p·ul·blockquote, 줄바꿈 `<br>`, HTML 이스케이프)과 텍스트, 태그 `#…`
  - **사진은 붙여넣기로 넘어가지 않음**(로그인해야 받는 주소) → 본문에 `[사진 N]` 자리 표시, 번호는 본문에 나오는 순서
- API: `POST /api/posts/{id}/export`(검증 → HTML·텍스트·사진 목록·경고, `publish_jobs`에 `manual_export` 기록),
  `GET .../export/photos.zip`(본문 순서대로 `01.jpg`…), `POST .../publish`(게시한 URL → published),
  `GET .../publish-status`
  - 내보내기를 막는 것: 본문·제목 없음. 경고만: 품질 검사 안 함, '꼭 고치기' 남음, 검사 뒤 본문 변경
- Vue "네이버에 올리기": 네이버 게시 순서 안내, 제목 복사, 서식 포함 복사(`ClipboardItem` HTML+텍스트, 안 되면 텍스트),
  텍스트만 복사, 사진 zip 받기, 번호 붙은 사진 미리보기, 게시 주소 기록·게시 완료 표시
- 실제 스택 확인: 업로드 순서와 다른 본문 순서 → zip `01.jpg`가 본문 첫 사진, 게시 기록·상태 조회
- 테스트 안정화: jsdom에 없는 레이아웃 API(`getClientRects` 등)를 `src/test-setup.ts`로 채움 — 편집기가 포커스 상태에서
  스크롤할 때 가끔 실패하던 테스트. 편집기 테스트는 고정 대기 대신 `editor.isInitialized`를 기다림
- 테스트: api 97개, worker 121개, web 46개

## Day 14 결과

- **구조화 로그**(기획서 23장): Laravel은 Monolog JSON(`LOG_STDERR_FORMATTER`), 워커는 JSON 한 줄(`app/logging_setup.py`)
  - trace_id: 요청마다 `AssignTraceId` 미들웨어(들어온 `X-Request-Id` 재사용) → `Context`로 로그·큐 작업에 자동 전파 →
    `AiWorkerClient`가 `X-Request-Id` 헤더로 워커에 전달 → 워커 요청·LLM 시도 로그에 같은 값. 큐·콘솔에서 시작한 작업도 새 trace_id
  - 이벤트: `llm_call`(Laravel: 용도·공급자·모델·결과·오류 종류·지연·토큰·글/프로젝트 id), `llm_attempt`·`request`(워커),
    `job_failed`, `stuck_work_recovered`, `database_backup`. **프롬프트·사실·본문·오류 메시지 원문은 남기지 않음**
  - 실제 확인: 분석 요청 1건의 trace_id가 queue 로그 2줄(Claude billing, GPT not_configured)·워커 로그 3줄에 동일
- 실패 작업: `GET /api/admin/failed-jobs`(예외 첫 줄만), `POST .../{uuid}/retry`(`queue:retry`), `DELETE .../{uuid}`
- `app:recover-stuck`(10분마다): 30분 넘게 planning·generating·analyzing·pending(참고자료·사진)인 항목을 실패로 돌려 재시도 가능하게
- `app:backup`(매일 04:00 KST): `VACUUM INTO`로 실행 중 DB를 일관되게 복사, 최근 14개 유지. 호스트 `data/backups/`에 바로 보임
- 관리 화면 `/admin`(헤더 "관리"): 글·초안·게시 수, AI 호출·실패율, 토큰(금액 없음), 초안 평균 생성 시간,
  용도·모델별 표, 일별 호출, 실패 작업 다시 실행·지우기
- 버그 수정: 헬스 체크가 `AiWorkerClient`를 거치지 않아 trace_id가 빠지던 것
- 테스트: api 104개, worker 123개, web 48개

## 2026-09-27 추가: 1인용 자동 로그인

- 사용자 요청: 회원가입 없음, 기본 관리자 계정 `admin`, 혼자 쓰므로 로그인 화면 불필요
- `users.username` 추가, 아이디 또는 이메일로 로그인(`login` 필드), `app:create-user admin`으로 계정 생성(비밀번호는 DB 해시만)
- `AutoLogin` 미들웨어 + `AUTH_AUTO_LOGIN=true`: 세션이 있는 브라우저 요청을 admin으로 로그인. 로그아웃 버튼 숨김
  - Laravel이 인증 미들웨어를 우선순위로 앞당기므로 `prependToPriorityList`로 AutoLogin을 인증보다 먼저 실행
- **보안 조치**: 포트를 `127.0.0.1:8080`에만 연결 — 같은 Wi-Fi의 다른 기기에서 접속 불가(LAN IP로 연결 거부 확인).
  세션 없는 외부 호출(curl 등)은 계속 401
- 테스트: api 107개, web 48개

## 2026-09-27 추가: 관리 화면에서 LLM API 관리

- `/admin` "AI 공급자": 공급자별 키 출처(관리 화면/.env/없음)·가린 키(`sk-ant-…wAA`), 키 저장·삭제, 연결 테스트
  (오류 종류를 한국어로: 크레딧 부족·키 오류 등), 시도 순서 편집(공급자·모델, 위/아래·추가·삭제, 기본값으로)
- 키는 `app_settings`에 `Crypt::encryptString`(APP_KEY)으로 암호화 저장. 응답에 원문 없음. 관리 화면 값 > .env 값
  - **APP_KEY를 바꾸면 저장된 키를 풀 수 없다** → 관리 화면에서 다시 입력
- 워커에는 LLM을 부르는 요청에만 `X-LLM-Config`(base64 JSON: 키·순서) 헤더로 전달 → **워커 재시작 없이 즉시 적용**.
  사진 처리·파싱·품질 검사·헬스 체크에는 키를 보내지 않는다. 워커는 헤더가 없거나 잘못되면 자기 환경변수를 쓴다
- 테스트: api 114개, worker 128개, web 53개

## 2026-09-27 추가: 단계별 마법사 + 한 번에 붙여넣기

- 사용자 피드백: 한 화면에 전부 나와 헷갈림, 네이버에 하나하나 올리기 번거로움
- **네이버 직접 업로드 불가 확인**: 공식 블로그 글쓰기 API는 2020년 5월 종료(광고성 글 대량 게시 차단).
  에디터 자동 조작·쿠키 저장 방식은 계정 제재 위험으로 제외 → 반자동 "한 번에 붙여넣기"로 결정
- 화면 흐름
  - 홈(`/`): "새 글 쓰기" + 내 글 목록. 헤더: 키워드·참고 글(`/projects`, 고급), 관리
  - 마법사(`/write` → `/write/:id?step=photos|details|progress`): ① 키워드·종류 ② 사진 ③ 알려줄 내용·말투·길이
    (+ 참고 글 추가 접기) → **"글 만들기" 한 번**
  - 글 화면(`/posts/:id`): 왼쪽 제목(AI 후보 선택)·편집/미리보기/비교, 오른쪽 "네이버에 올리기"·게시 전 검사.
    아래 접기: "사진·내용 고치고 다시 만들기", "AI가 세운 글 계획 보기". 예전 `/posts/:id/edit`는 새 주소로 이동
- `POST /api/posts/start`(키워드로 프로젝트 재사용/생성 + 글 생성), `POST /api/posts/{id}/autopilot`:
  Bus 체인 = 사진 분석(안 한 사진만) → 키워드 분석(없거나 오래됐거나 해석 없을 때만) → 계획 → 초안(+검사)
  - posts: `pipeline_status`(running·done·failed)·`pipeline_step`·`pipeline_error`. `TracksPipeline` 트레이트
  - 사진·키워드 분석이 AI 없이 끝나도 계속 진행, 계획이 실패하면 멈추고 초안은 건너뜀. 중복 클릭 무시.
    `app:recover-stuck`이 멈춘 체인도 정리
- 네이버에 올리기: 화면이 미리 본문을 준비(내보내기 `record=false`) — 사진을 받아 1280px JPEG data URI로
  `[사진 N]` 자리에 넣음 → 버튼 한 번에 **클립보드 복사(HTML+텍스트) + 네이버 글쓰기(`GoBlogWrite.naver`) 열기**.
  본문 붙여넣기 한 번 + 제목 복사. 사진이 안 들어가면 zip 예비
  - **네이버 에디터가 붙여넣은 data URI 이미지를 받는지는 실제 계정으로 확인 필요**
- 실제 스택 확인: 마법사 API 흐름 → 체인 vision→analysis→plan(billing 실패) → failed, 초안 건너뜀
- 테스트: api 122개, worker 128개, web 60개. 브라우저 확장이 연결되지 않아 화면 수동 확인은 못 함

## 2026-09-27 추가: 디자인 "Blog AI 1a" 적용

- 출처: claude.ai/design 프로젝트 `676652da…` 의 `Blog AI 1a.dc.html`(모바일 7 + 웹 7 화면). `support.js`는 디자인 캔버스 런타임이라 미사용
- 토큰(`src/assets/main.css` `@theme`): ink #22002E, cream #FFF4EA, lemon #FAFF5A, lilac #D896F4, lilac-soft, line, panel,
  sub·body·muted·accent. 글꼴 Bagel Fat One(제목) + Pretendard(본문, CDN). 기존 stone·amber 클래스는 디자인 팔레트로 재매핑
- 흐름 6단계(디자인 그대로): ①키워드 ②사진 ③알려줄 내용 ④글 계획 ⑤초안 다듬기 ⑥네이버에 올리기
  - 라우트: `/`(홈), `/write`(1단계 새 글), `/posts/:id/:step`, `/posts/:id`는 이어서 할 단계로 이동(`ResumeView`)
  - 웹: 왼쪽 단계 목록(완료 ✓·지금 할 일·다음) + 가운데 작업 + 오른쪽 도움말(320px). 모바일: ←·글 이름·N/6 + 6칸 막대,
    도움말은 본문 아래, 하단 고정 버튼(모바일은 위 ←가 이전)
  - 홈: "오늘은 어떤 글을 쓸까요?" + 키워드 입력 → 1단계, "쓰던 글" 진행률·이어서/보기
- 디자인 흐름에 맞춘 기능 변경
  - ③ "글 계획 만들기" = `autopilot until=plan`(사진 분석→키워드 분석→계획까지), ④에서 제목·목차 고른 뒤
    "이 계획으로 초안 쓰기" = 계획 저장 + 초안(+검사). 초안이 있으면 다시 쓰기 전에 한 번 더 확인
  - ⑤ 검사가 짚은 문장을 본문에서 노랗게 표시(`IssueHighlight` TipTap 확장), "문장 지우기"(그 문장만 삭제 후 저장·재검사),
    "사실로 추가"(워커 이슈의 `suggested_fact_key`로 사실 추가 후 재검사), "위치"
  - ⑥ 디자인의 단계 목록 모양에 기존 "한 번에 붙여넣기"를 넣음: 1 본문(사진 포함) 복사하고 네이버 열기 → 2 붙여넣기 →
    3 제목 복사 → 4 주소 기록. zip·서식 없이 복사는 오른쪽 예비
  - 헤더 "자동 저장됨"은 편집기 저장 상태와 연결
- 화면 확인: Playwright(임시 설치)로 웹 1280×820·모바일 390×844 전 화면 캡처해 디자인과 대조. 샘플 글은 확인 후 삭제
- 테스트: api 124개, worker 128개, web 65개

## 2026-09-28 추가: 디자인 1a와 1:1로 다시 맞춤

사용자 지적("디자인이 링크랑 다르다")에 따라 디자인 원본(내용 변경 없음 확인)과 화면을 항목별로 대조해 고침.
- 넓은 화면: 가운데 상자(max-width) 제거 — 디자인 그리드(260 | 1fr | 320)가 화면 전체를 쓴다. 홈도 같음
- 모바일 단계 화면: 앱 머리줄(Blog AI) 없이 "← 글 이름 N/6"으로 시작(라우트 `meta.flow`). 제목은 디자인처럼 두 줄
  (`StepLayout` 제목의 `\n`은 모바일에서만 줄바꿈)
- ① 모바일: "잘 쓴 글 참고하기"는 접힌 점선 카드, 누르면 입력이 열림
- ② "얼굴이 보이는 사진 N장"(비전 privacy_flags가 얼굴뿐일 때), 모바일은 노란 한 줄 + "위치 정보는 항상 지워요 · N / 30장"
- ③ 칩을 디자인 7개로(메뉴·꼭 넣을 내용 제거, "직접 입력"은 유지), 웹은 제목 아래 설명 없음, 버튼 "다음 · 글 계획 만들기 →"
- ④ 버튼은 항상 "이 계획으로 초안 쓰기 →"(초안이 있고 바뀐 게 없으면 바로 5단계), 모바일은 목차 아래 노란 "쓰지 않을 내용"
- ⑤ 탭(편집·미리보기·AI 초안과 비교)을 제목 오른쪽에. 편집 도구 줄은 본문을 누르고 있을 때만 보임(정지 화면은 디자인과 같음).
  본문 사진은 낮은 칸 + "사진 N". 모바일: 요약 칩 + 아래 [미리보기][다음 확인 →](확인할 곳을 차례로 보여줌)
- ⑥ 디자인 순서로: 1 제목 복사(처음 누를 때 네이버 글쓰기 열기) → 2 본문 복사(사진까지 본문에 넣은 한 번 붙여넣기)
  → 3 사진 N장 받기(빠졌을 때 예비, zip) → 4 붙여넣기(클립보드의 게시 주소를 칸에 넣음). 초록 버튼 없앰, 모바일은 점수 카드가 위
- 관리(/admin): 디자인 언어로 새로 — 가운데 AI 공급자·시도 순서·호출 표, 오른쪽 패널에 사용량 점수 카드·실패 작업(노란 카드)
- 배포: `index.html`에 `Cache-Control: no-cache`(새 화면이 바로 보이게)
- 테스트: web 66개(6단계 순서·붙여넣기 테스트 추가)

## 2026-09-28 추가: 디자인에만 있던 기능 구현 + 나머지 화면도 디자인 언어로

- ② 사진을 올리면 바로 분석 시작(`images/analyze`), 2.5초마다 결과 반영 → "사진 분석 중 · n / N장" → "✓ N장 모두 분석됨".
  실패(크레딧 부족 등)면 노란 "분석하지 못한 사진 N장 · 지금 다시 분석"
  - 겹침 방지: `AnalyzeImagesJob`은 이미 결과가 있는 사진을 건너뛴다(`force` 요청만 다시 분석). autopilot은 분석 중(pending)인
    사진도 흐름에 넣어 계획 전에 결과가 있게 한다
- ④ "아직 안 쓰인 사진 — 목차에 끌어다 놓기": 오른쪽 패널의 사진을 목차 줄에 끌어 놓으면 그 섹션에 들어가고, 목차의 사진을
  다른 줄로 옮기거나 패널에 놓아 뺄 수 있다(한 사진은 한 섹션에만). "이 계획으로 초안 쓰기" 때 저장
- 키워드·참고 글 관리(`/projects`, `/projects/:id`): 홈과 같은 라일락 카드 + 목록, 상세는 단계 화면처럼 오른쪽 패널(참고 글 수·
  작성한 글·마지막 분석·새 글 쓰기·삭제). 참고자료·분석 패널, 로그인, 미리보기/비교도 디자인 토큰으로
- 옛 화면 컴포넌트 삭제: PhotoGrid, PhotoUploader, PlanPanel, QualityPanel, FactsEditor(+테스트)
- 테스트: api 126개, worker 128개, web 54개(옛 컴포넌트 테스트 12개 삭제, 사진 분석·사진 끌어 놓기 테스트 추가)

## 2026-09-28 추가: 상위 노출 가이드 + 해시태그 자동 달기

키워드(프로젝트)를 분석하면 "상위 노출 가이드"와 "추천 해시태그"가 함께 나오고, 그 키워드로 초안을 쓰면 해시태그가 자동으로 달린다.
네이버는 순위 기준을 공개하지 않으므로 가이드는 **참고 글 통계 + 네이버가 공개한 검색 원칙**에 근거한 목표치이며 순위 보장이 아니라고 화면에 적는다.
- 워커(코드 계산, `app/analyzers/exposure.py`, guide-1)
  - 참고 글 특징에 `hashtags`(본문의 #태그, 숫자만 있는 것 제외) 추가 — features-1에 기본값 있는 항목으로 더함(옛 특징은 빈 목록)
  - 통계에 `hashtags`(문서 빈도)·`hashtag_count` 추가
  - 목표치(targets): 제목(키워드로 시작 비율)·제목 길이·본문 길이·사진·소제목(각 p25~p75)·키워드 반복 횟수·첫 문단 키워드·
    꼭 담을 정보(참고 글 절반 이상이 다룬 항목)·해시태그 수·마무리. 각 항목에 근거(basis) 문장. 참고 글이 없으면 일반 기준
  - 원칙(principles): 직접 경험·사진, 키워드 억지 반복 금지, 베끼기·짜깁기 금지(유사 문서), 한 주제 꾸준히, 관련 태그만, 협찬 표시
  - 추천 해시태그: 키워드 붙여 쓰기 → 지역+주제 조합(인계동파스타, 수원파스타) → 지역+카테고리(인계동맛집) → 참고 글 30% 이상이 단 태그 →
    AI 추천(keyword-analysis-v2의 `hashtags`). 정규화·중복 제거, 20개까지
  - 초안: 요청의 `hashtags`(키워드 해시태그)를 앞에 두고 AI 태그로 채운다(`merge_tags`, 최대 30)
  - 품질 검사(quality-2): 가이드 기준(`guide` = checks)으로 해시태그 부족, 사진·소제목 부족, 첫 문단 키워드, 제목 키워드 시작을 info로,
    태그 30개 초과를 warning으로
- Laravel: `keyword_analyses.guide_json`, `keyword_projects.hashtags_json`(사용자가 고친 목록, null이면 분석 추천).
  `PUT /api/projects/{id}/hashtags`(정규화·30개), `KeywordProject::hashtags()`, 초안 요청·품질 검사 요청에 전달,
  글 응답에 `recommended_hashtags`(글 하나를 불러올 때만)
- 화면
  - 키워드 상세 › 키워드 분석: "상위 노출 가이드"(목표치·근거, 원칙 접기) + "글에 자동으로 달 해시태그"(빼기·추가·추천 다시 넣기·저장·추천으로 되돌리기)
  - ④ 오른쪽 패널 "달 해시태그 N개 · 초안을 쓰면 자동으로 달아요"
  - ⑤ 해시태그 입력 아래 "키워드 추천 + #태그 / 모두 달기", 검사 결과에 가이드 항목
  - ⑥ 해시태그는 본문 끝에 들어가고(기존), "태그 복사" 단계로 발행 창 태그 칸에도 붙여넣기
- 테스트: api 131개, worker 139개, web 59개

## 2026-09-28 추가: 글 삭제 + 빈 글 중복 방지

- `DELETE /api/posts/{id}`: 글·사실·사진 파일(원본·썸네일)을 지우고, AI 사용 기록(generations)은 post_id만 비워 사용량 통계에 남긴다.
  대기 중인 글 작업(사진 분석·계획·초안)은 `deleteWhenMissingModels`로 조용히 버린다
- 홈 "쓰던 글" 카드마다 ✕ → 카드 안에서 "지울까요? 사진도 함께 지워지고 되돌릴 수 없어요 [지우기][취소]"(브라우저 확인 창 없음)
- `POST /posts/start`: 같은 키워드로 시작만 하고 비어 있는 글(사진·사실·계획·초안 없음)이 있으면 새로 만들지 않고 그 글을 돌려준다(200)
- AI 공급자 "크레딧 부족" 확인(2026-09-28): 두 공급자 모두 실제 응답이 API 크레딧 없음
  (Anthropic 400 "credit balance is too low", OpenAI 429 `insufficient_quota`/`credit_balance_exhausted`). 키는 유효.
  Claude·ChatGPT 구독과 API 크레딧은 별개
  - 모델을 바꿔도(claude-haiku-4-5, gpt-4o-mini) 같은 오류 → 계정 단위. 키가 속한 계정: Anthropic 조직 `92bb7358-…`,
    OpenAI 조직 `user-9ioz…`(개인 계정)·프로젝트 `proj_lc0l…`
- 관리 › 연결 테스트가 실패하면 **키가 속한 계정(조직·프로젝트 ID, 응답 헤더 `anthropic-organization-id`·`openai-organization`/`openai-project`)**과
  **공급자 응답 원문**(sk-… 가림, 300자)을 보여준다. 크레딧을 넣은 조직과 비교하게 하려는 것. 로그에는 남기지 않는다(`LLMError.account`)

## 2026-09-28 추가: Claude 구독(Claude Code)으로 글쓰기 — API 크레딧 없이

API 크레딧이 없어 생성이 멈춰, 이 Mac에 로그인된 Claude Code(구독)를 세 번째 공급자 `claude_code`로 붙였다.
- `infra/claude-bridge/bridge.py`(표준 라이브러리): 127.0.0.1:8790, 토큰 인증, `claude -p --safe-mode --tools "" --no-session-persistence
  --output-format json --model <opus|sonnet|haiku> --system-prompt … [--json-schema …]`, 프롬프트는 stdin, 요청마다 임시 폴더,
  사진은 임시 파일로 두고 그때만 Read 허용, ANTHROPIC_API_KEY는 넘기지 않음(구독 로그인 사용), 동시 2개, 로그에는 시간·모델·토큰만
- 워커 `ClaudeCodeAdapter`: pydantic 스키마를 `--json-schema`로 넘기고 `structured_output`을 검증. 연결기 꺼짐→unavailable,
  구독 한도→rate_limited(다음 대상으로), 로그인 문제→auth. 토큰(`CLAUDE_BRIDGE_TOKEN`)이 있어야 등록
- 기본 시도 순서: `claude_code:opus, claude_code:sonnet, anthropic:claude-opus-5, openai:gpt-5.5`
- 관리 화면: "Claude 구독 (Claude Code)" 카드(키 입력 없음, 연결기 설정됨/미설정 안내, 구독 사용량 링크)
- 실행: `make claude-bridge-install` → LaunchAgent(로그인 시 자동, KeepAlive). macOS가 launchd의 데스크톱 폴더 접근을 막아
  bridge.py·토큰을 `~/.blog-ai`에 복사해 실행
- 실제 확인(2026-09-28): 연결 테스트 claude-opus-5-5 성공, 사진 3장 분석 → 키워드 분석 → 계획 → 초안까지 약 1분 30초에 완료,
  추천 해시태그 15개 자동으로 달림, 검사 82.5점
- 테스트: api 132개, worker 150개, web 60개

## 2026-09-28 추가: ChatGPT 구독(Codex CLI)도 같은 방식으로

- 공급자 `codex`(gpt-5.5 등): 같은 claude-bridge가 `codex exec --skip-git-repo-check --ephemeral --ignore-user-config --ignore-rules
  -s read-only -C <임시 폴더> -m <모델> --json -c developer_instructions=… [--output-schema 파일] [-i 사진…] -- -`를 실행,
  JSONL 이벤트에서 답(agent_message)과 토큰(turn.completed)을 읽는다. OPENAI_API_KEY는 넘기지 않음(ChatGPT 로그인 사용)
- 워커 `SubscriptionAdapter`(claude_code·codex 공용). codex에는 OpenAI 엄격 스키마(`to_strict_json_schema`). 로그인 만료(401)→auth
- Codex CLI 설치: `npm install -g @openai/codex`(0.158.0), 로그인: `codex login`(ChatGPT 계정). 만료되면 다시 로그인
- 기본 순서: `claude_code:opus, claude_code:sonnet, codex:gpt-5.5, anthropic:claude-opus-5, openai:gpt-5.5`
- 실제 확인(2026-09-28): codex만으로 사진 2장 분석 → 키워드 분석 → 계획 → 초안 완료(검사 75점, 해시태그 자동)
- 테스트: api 132개, worker 152개, web 60개

## 2026-09-28 추가: 모델 고르기 + API 연결 꺼 둠(주석)

- 관리 › 시도 순서의 모델을 드롭다운으로. 목록은 연결기 `GET /models`가 준다: Claude Code는 opus·sonnet·haiku,
  Codex는 `~/.codex/models_cache.json`의 공개(list) 모델(gpt-6-astra·gpt-6-sol·gpt-6-luna·gpt-5.6-*·gpt-5.5).
  워커 `GET /llm/models` → Laravel(성공만 10분 캐시, 실패하면 기본 목록). 저장값이 목록에 없으면 "(목록에 없음)"으로 보여 준다
- **API 연결 꺼 둠(주석 처리, 코드는 남김)**: 구독 두 개로 충분해 Anthropic·OpenAI API 공급자를 끔
  - Laravel `LlmSettings::PROVIDERS`의 anthropic·openai, `routes/api.php` 키 관리 경로, 워커 헤더의 키 — 주석
  - 워커 `app/llm/factory.py`의 API 어댑터 등록 — 주석(`anthropic_adapter.py`·`openai_adapter.py`와 그 테스트는 남김)
  - `.env`의 `ANTHROPIC_API_KEY`·`OPENAI_API_KEY` 주석, 관리 화면에 저장돼 있던 키는 삭제
  - 다시 켜려면 "[API 연결 꺼 둠" 표시가 있는 주석을 모두 푼다
- 기본 순서: `claude_code:opus, claude_code:sonnet, codex:gpt-6-astra`
- 테스트: api 131개, worker 152개(+1 건너뜀: API 헤더 키 테스트), web 61개

## 2026-09-28 추가: Tailscale로 휴대폰·다른 기기에서 접속

- Docker 포트는 그대로 `127.0.0.1:8080`(자동 로그인이라 같은 와이파이에 열지 않음). `tailscale serve --bg --http=8080 http://127.0.0.1:8080`로
  내 tailnet 기기에만 연다(재부팅해도 유지). 주소: `http://leejk-macbookpro:8080`, `http://leejk-macbookpro.tail01cfec.ts.net:8080`
- IP(`100.108.73.0:8080`)로는 serve가 404를 준다(이름으로만 응답) → 기기 이름 주소를 쓴다
- `.env` `SANCTUM_STATEFUL_DOMAINS`에 두 주소를 더해 로그인 쿠키(자동 로그인)가 동작. 확인: 두 주소 모두 `/api/user` 자동 로그인, 모바일 크기 브라우저에서 홈·관리 오류 없음
- 끄기: `tailscale serve --http=8080 off`

## 2026-09-28 추가: 카테고리별 학습

관리 › "키워드·참고 글 관리"를 **"카테고리별 학습"**으로 바꿨다. 카테고리(예: 맛집·카페)를 만들고 잘 쓴 글 URL(또는 본문 붙여넣기)을
넣어 "학습하기"를 누르면, 글쓰기 1단계에서 그 카테고리를 골랐을 때 글 구성·사진 배치·해시태그에 반영된다.
- 데이터: `keyword_projects.kind`(keyword | category), `learning_category_id`(키워드가 고른 학습 카테고리, 같은 테이블).
  이름 유일성은 (user_id, kind, keyword). 카테고리는 프로젝트를 그대로 써서 참고 글·분석·가이드·해시태그 화면을 재사용
- 키워드 분석(`AnalyzeKeywordJob`)은 자기 참고 글 + 고른 카테고리의 참고 글 특징을 합쳐 통계를 낸다(`sourceProjectIds`),
  카테고리에 글을 더하면 키워드 분석도 다시 하도록 `sourceHash`에 포함
- 해시태그: 키워드에 직접 고친 목록이 없으면 키워드 분석 추천 + 카테고리 해시태그(직접 고친 것 또는 학습 추천)를 합친다
- API: `GET /projects?kind=category`, `POST /projects {kind:'category'}`, `POST /posts/start {learning_category_id}`,
  `GET /posts?learning_category_id=`. 글 응답에 `learning_category`
- 화면: 카테고리별 학습 목록(만들기·✕ 삭제), 카테고리 상세("학습할 글"·"학습하기/다시 학습"·"학습 결과", 이 카테고리로 쓴 글,
  "이 카테고리로 새 글 쓰기" → 1단계에서 미리 선택). 1단계 카테고리 칩 = 학습 카테고리(+ 선택 안 함, + 카테고리 학습시키기),
  1단계 오른쪽 참고 글 URL 입력은 그대로(그 키워드만의 참고 글)
- 가이드 목표치: 참고 글이 하나면 범위 대신 한 값("16~16자" → "16자")
- 실제 확인: 카테고리 생성 → 본문 붙여넣기 → 학습(구독 LLM) → 해시태그에 참고 글 태그 반영 → 1단계에서 골라 시작 시 추천 해시태그 적용. 데모 데이터는 삭제
- 테스트: api 135개, worker 153개(+1 건너뜀), web 62개

## 2026-09-28 추가: 학습 진행 상황 표시

- 서버: `keyword_projects.analysis_step`(queued → ai → null)·`analysis_started_at`. 학습을 누르면 queued, 작업이 시작되면 ai,
  끝나거나 실패하면 null. `GET /projects/{id}/analysis`·`POST …/analyze` 응답에 `progress`(step·started_at·학습할 글 읽기 현황 total/parsed/pending)
- 화면(`LearningProgress.vue`): 학습할 글 읽기(N/M개) → 차례 기다리기 → AI로 글 구성·사진 배치 정리 → 노출 가이드·해시태그 만들기.
  서버가 알려 준 단계만 진행 중으로, 경과 초·"보통 20~60초", 진행 막대(AI 단계는 예상 시간에 가까워질수록 느리게, 92%를 넘지 않음, 반짝임)
- 상태 확인 2초마다, 끝나면 오른쪽 패널(마지막 학습)도 새로 읽음. 실제 학습으로 확인 후 데모 카테고리 삭제
- 테스트: api 136개, worker 153개(+1 건너뜀), web 64개

## 2026-09-28 추가: 북마크 버튼 "Blog AI로 보내기"(붙여넣기 없이 학습)

네이버 글 본문 붙여넣기가 번거로워, 사용자 브라우저에서 보고 있는 글을 버튼 한 번으로 보낸다(서버는 네이버에 접속하지 않음 — 붙여넣기와 같은 방식, 한 번에 한 글).
- `lib/bookmarklet.ts`: 즐겨찾기용 `javascript:` 코드. 네이버 PC(`#mainFrame` 안 문서)·모바일의 SmartEditor(`.se-main-container`,
  `se-sectionTitle`→"# 소제목", `se-image/imageGroup/imageStrip`→"[사진]"×장수, `se-text-paragraph`→문단, `.wrap_tag` 해시태그),
  그 밖의 사이트는 article/main/body에서 h1~h4·p·li·img로 같은 형식을 만든다. `window.open(APP/import)` 후 postMessage로 넘기고 받음 확인까지 재전송
- 받기 화면 `/import`(ImportView): 제목·주소·글자/소제목/사진/해시태그 요약·미리보기, 학습 카테고리(마지막 선택 기억), "추가하고 바로 학습"
  (글 읽기가 끝날 때까지 기다린 뒤 학습 시작). 이미 있는 글이면 알려 줌
- 서버: 본문 추가에 `source_url`(원래 주소) 허용. 같은 주소가 이미 읽혔으면 건너뛰고, "본문 필요"·실패였던 같은 주소면 그 항목에 본문을 채운다.
  본문이 있으면 파서는 항상 본문으로 읽는다(주소에 접속 안 함)
- 설치: 관리 › 카테고리별 학습 오른쪽 노란 카드의 "★ Blog AI로 보내기"를 즐겨찾기바로 끌어다 놓기(안 되면 주소 복사). 설치 시점의 Blog AI 주소
  (localhost·Tailscale)로 보낸다
- 확인: 네이버 구조를 흉내 낸 로컬 페이지(iframe mainFrame 포함)에서 버튼 실행 → 받기 화면(제목, 소제목 2·사진 3·해시태그 3) → 추가·학습까지.
  실제 네이버에는 자동 도구로 접속하지 않았다
- 테스트: api 138개, worker 153개(+1 건너뜀), web 67개
- 네이버 주소 맞추기(`ReferenceUrl::canonicalNaver`): PC `blog.naver.com/{아이디}/{글번호}`, 모바일 `m.blog…`, `PostView.naver?blogId=&logNo=`,
  `/{아이디}?logNo=`를 모두 `https://blog.naver.com/{아이디}/{글번호}`로. 예전에 저장된 주소도 비교할 때 같은 방식으로 맞춘다
  → 기존 "본문 필요" 항목을 다른 모양의 주소 화면에서 보내도 그 항목이 채워지고, 이미 읽은 글은 중복되지 않는다
- 버튼은 네이버 PC에서 글 프레임(`mainFrame`)의 주소를 보낸다. 버튼 코드가 바뀌면 즐겨찾기의 버튼을 새로 끌어다 놓아야 한다

## 2026-09-28 추가: 버튼의 사진 세기 정확도 + 다시 보내면 다시 읽기

- 실제 보낸 글에서 사진이 문단마다 끼어(`IPIPIP…`) 86~220장으로 잡혀, 스티커·작은 이미지를 사진으로 세던 것을 고쳤다
  - 사진으로 세지 않음: `se-sticker`·`se-oglink`(링크 미리보기)·`se-map/placesMap`·`se-video`·`se-file` 등 블록, 폭 200px 미만
    (원본 `data-width`·`width`·화면 폭 중 큰 값), 이름/주소에 sticker·emoticon·emoji·icon, 같은 이미지(주소) 반복
  - 확인: 네이버 흉내 페이지에 스티커·40px 이모티콘·반복 이미지·링크 미리보기를 섞어도 사진 3장으로 정확히 셈
- 같은 주소의 글을 다시 보내면 건너뛰지 않고 그 항목을 새 본문으로 다시 읽는다(특징은 덮어씀, 중복 안 만듦)
  → 이미 보낸 글은 글을 열고 버튼을 다시 누르면 새 기준으로 갱신된다(버튼은 즐겨찾기에 새로 끌어다 놓아야 함)

## 2026-09-28 추가: 토큰 누수 방지

점검 결과 AI 호출은 모두 사용자가 누를 때만 일어난다(글 계획·초안·다시 쓰기·AI 문단·학습·연결 테스트, 사진은 올린 직후 한 번).
예약 작업(멈춘 작업 정리·백업)과 품질 검사는 AI를 부르지 않는다. "혹시나" 새는 구멍을 막았다.
- **기다리는 시간 정렬**: 연결기 280초 → 워커 290초 → Laravel 320초(`AiWorkerClient::LLM_TIMEOUT`) → 작업 330초 → queue:work 340초 →
  큐 retry_after 360초, nginx 330초. 뒤쪽이 더 오래 기다려 "AI는 아직 쓰는 중인데 포기하고 다시 보내는" 일이 없다
- **시간 초과면 멈춤**: 워커 라우터는 `timeout`이면 다음 모델로 넘어가지 않는다. Laravel은 보낸 뒤 시간 초과(cURL 28)를
  `LlmCallAbandonedException`으로 바꾸고, AI 작업 4개(`NoRetryAfterLlmStop` 미들웨어)는 재시도 없이 실패로 끝낸다(이유를 화면에 표시).
  워커에 닿지도 못한 경우(연결 거부)는 AI를 안 썼으니 그대로 재시도
- **시간당 한도**: 최근 1시간 AI 호출(`generations`)이 `LLM_MAX_CALLS_PER_HOUR`(기본 60)를 넘으면 새로 부르지 않는다
  (`LlmBudgetExceededException`, 재시도 없음, 한 시간 안에 풀림). 관리 화면에 "최근 1시간 AI 호출 N / 60"
- **보내기 학습 모으기**: 받기 화면의 "추가하고 학습"은 45초 뒤로 예약하고, 그동안 더 보낸 글은 같은 학습에 들어간다
  (대기 중이면 서버가 새로 넣지 않음) → 7개 보내도 학습 1번
- 테스트: api 145개, worker 155개(+1 건너뜀), web 67개

## 2026-09-29 추가: 네이버·티스토리 분리 + 티스토리 올리기 + 카카오로 상위 글 학습

- **올릴 곳(platform: naver | tistory)**을 `keyword_projects`·`posts`에 둔다(기존 데이터는 모두 naver). 같은 이름의 카테고리·키워드도
  플랫폼별로 따로(unique: user_id, kind, platform, keyword). 분석 캐시 키(`sourceHash`)에도 플랫폼이 들어간다
- 워커: 프롬프트 v3(keyword-analysis·writing-plan·blog-draft)에 `platform` 입력. 티스토리는 다음·구글 검색 기준
  (첫 한두 문장 요약, 소제목 구조, 태그는 본문 대신 태그 칸). `build_guide(..., platform)`이 `TISTORY_PRINCIPLES`를 쓴다
- 화면: 카테고리별 학습에 네이버 | 티스토리 탭(`?platform=tistory`), 글쓰기 1단계 "올릴 곳"(그 플랫폼 카테고리만 보임, 마지막 선택 기억),
  6단계 네이버 | 티스토리 탭(같은 글을 두 곳에 올릴 수 있음. 게시 주소는 `published_url` / `tistory_url` 따로 기록),
  글 받기(북마크)는 보낸 글 주소의 플랫폼 카테고리만 보여 준다. 북마클릿이 티스토리 본문·태그·제목도 읽는다
- 티스토리 올리기: 공식 글쓰기 API가 끝나 네이버처럼 붙여넣기. 관리 › 내 티스토리 블로그에 주소를 넣으면
  `https://{주소}/manage/newpost`가 열린다. 본문에는 해시태그를 넣지 않고 "태그 복사"가 `a,b,c`로 복사한다
  (내보내기 `POST /posts/{id}/export`에 `platform`을 주면 그 기준으로 만든다. 글에는 저장하지 않음)
- **티스토리 상위 글 자동 학습**: 카카오(다음) 블로그 검색 API(`App\Services\KakaoSearch`, 정확도순 2페이지까지)로
  `*.tistory.com` 글 주소만 추려(관리·검색·태그·카테고리 주소 제외) 참고 글로 넣고(`source_type=kakao_search`), 워커가
  robots.txt를 지키며 한 편씩 읽어 특징만 남긴다. 60초 뒤 학습 예약. `POST /projects/{id}/discover {query,size≤20}`(분당 10회),
  티스토리 카테고리만. 네이버는 여전히 서버가 가져오지 않는다
- 카카오 REST API 키: 관리 화면에서 넣으면 `app_settings.kakao.rest_api_key`에 암호화 저장(`.env`의 `KAKAO_REST_API_KEY`는 대체값).
  화면·API는 넣었는지(`kakao_ready`)만 알려 준다. "연결 확인" = `POST /settings/blogs/kakao-test`
- 설정 API: `GET/PUT /api/settings/blogs`(tistory_host, kakao_key), `/api/user` meta에 `tistory_host`, `kakao_ready`
- 테스트: api `TistoryTest`(8개), web 티스토리 6단계, worker 플랫폼 가이드·프롬프트. 실제 카카오 키로는 아직 확인 전
- 실제 키로 확인(2026-09-29): 다음 블로그 검색 "수원 맛집" 상위 100개 중 티스토리는 1개(대부분 네이버). "수원 맛집 티스토리"는 60개.
  그래서 검색어 그대로의 상위 티스토리 글을 먼저 담고, 모자라면 "검색어 티스토리"로 찾은 상위 글로 채운다(각 2페이지까지)
- 티스토리 전용 본문 추출기(`app/references/tistory.py`): 일반 추출기(trafilatura)가 티스토리 사진 블록(`figure.imageblock`)을 버려
  사진 수가 0으로 나왔다(10편 중 9편). 본문 상자(`.tt_article_useless_p_margin` 등)를 글 순서대로 읽어 사진·그리드 사진을 세고
  이모티콘·링크 미리보기·광고·관련 글은 뺀다. 못 찾으면 일반 추출기로. 티스토리 맛집 카테고리 10편을 다시 읽어 사진 2~62장으로 잡혔고
  다시 학습해 가이드가 "사진 6~31장"으로 바뀜

## 2026-09-29 추가: 동시에 올리기(짝 글)와 홈 글 목록 분리

- 같은 글을 네이버·티스토리에 그대로 올리면 검색엔진이 중복 문서로 보고 둘 다 밀어낼 수 있어서, **플랫폼마다 따로 쓴 짝 글**을 만든다
  - `posts.twin_of_post_id`(짝 글 → 원래 글, 원래 글을 지우면 짝 글은 혼자 남음), `twin_source_hash`, `post_images.source_image_id`
  - 글쓰기 1단계 "올릴 곳"에 **둘 다**: 네이버 글 + 티스토리 짝 글을 만들고 학습 카테고리를 플랫폼마다 하나씩 고른다
  - 사진·알려줄 내용은 원래 글에만 넣는다. 원래 글 '글 만들기' 흐름에서 사진 분석이 끝나면 `StartTwinJob`이 사실·사진(분석 결과 포함,
    파일은 따로 복사)을 짝 글로 가져가 짝 글 초안까지 쓴다(`App\Services\TwinPosts`, `App\Services\Autopilot`).
    가져온 내용이 바뀌면 다시 쓰되, 사용자가 짝 글을 고쳤거나 이미 올렸으면 저절로 덮어쓰지 않는다
  - 계획·초안 프롬프트 v4(writing-plan-v4, blog-draft-v4): `twin=true`면 제목·도입·구성·문장을 다르게(사실은 그대로)
  - 6단계 다른 플랫폼 탭은 짝 글을 올린다(쓰는 중 표시, 문장 겹침 %, 짝 글 다듬기 링크, 처음부터 다시 쓰기).
    짝 글이 없으면 중복 문서 경고와 "티스토리용으로 따로 쓰기"(`POST /posts/{id}/twin`). 겹침: `GET /posts/{id}/twin`의 `overlap`
    (`App\Support\TextOverlap`, 세 낱말 묶음 겹침 비율, 30% 이상이면 경고)
- 홈 "쓰던 글"을 네이버 | 티스토리 탭으로 나눔(짝 글은 "티스토리에도/네이버에도" 표시). `GET /posts?platform=`
- 6단계 이름을 "블로그에 올리기"로
- 테스트: api `TwinPostTest`(7개), web 둘 다 시작·짝 글 탭, worker twin 프롬프트

## 2026-09-30 추가: 사진 순서 = 올린 순서 + 찍은 시간 + AI 판단

- 사진을 올릴 때 워커가 EXIF 찍은 시각(DateTimeOriginal)을 `post_images.taken_at`에 남긴다(위치 정보는 지우고 시각은 남김)
- 계획 프롬프트 v5(writing-plan-v5): 사진을 올린 순서대로 주되 `upload_order`, `taken_at`(현지 벽시계 시각), `shot_order`,
  `minutes_after_first_shot`를 함께 준다(`photo_payload`). 찍은 순서를 이야기 뼈대로, 시각이 없거나 같으면 올린 순서,
  사용자가 일부러 옮긴 사진은 그 선택을 존중, 섹션은 사진 내용(vision)으로, 1시간 넘는 간격은 자연스러운 섹션 경계
- 2단계 사진: 썸네일에 찍은 시각 표시, 시각이 2장 이상이면 "찍은 시간순으로 정렬" 버튼(선택. 기본은 올린 순서 유지)

## 2026-10-02 추가: 3단계 "장소 연결"(지도 링크 → 카카오 로컬)

- 3단계에 네이버·구글·카카오 지도 링크나 가게 이름을 넣으면 장소 후보를 보여 주고, 고르면 알려줄 내용에 장소명·주소·연락처·업종을 채운다
  (사용자가 보고 고친 값만 사실로 저장). 고른 장소는 `posts.place_json`(이름·주소·전화·업종·좌표·카카오 id·지도 링크·출처), 짝 글에도 복사
- 링크는 열지 않는다: `App\Support\MapUrl`이 링크 글자에서 이름(/search/…, /maps/place/…)과 좌표(@위도,경도, lat/lng, 예전 네이버 c=메르카토르)만 읽는다.
  네이버 지도는 서버가 가져오지 않는 네이버 도메인, 구글 지도는 자동 수집 금지. 단축 링크(naver.me, maps.app.goo.gl)는 링크 뒤에 가게 이름을 같이 적게 한다
- 장소 확인은 카카오 로컬 API(`App\Services\KakaoLocal`): 이름 검색(좌표가 있으면 반경 3km), 좌표만 있으면 좌표→주소. `POST /api/places/lookup`(분당 30회)
- 카카오 앱에서 **카카오맵 사용 설정 ON**이 필요(꺼져 있으면 OPEN_MAP_AND_LOCAL 403 → 화면에 켜는 방법 안내). 2026-10-02 현재 꺼져 있음
- 테스트: api `PlaceTest`(4개), web 장소 찾기→채우기→저장
- 내보내기: 장소를 연결했으면 본문 끝(해시태그 앞)에 "📍 위치 / 이름 / 주소 / 전화 / 지도: 링크"를 넣는다(붙여넣은 지도 링크, 없으면 카카오맵).
  네이버·티스토리 둘 다. 6단계 본문 복사 줄에 "끝에 📍 위치·지도 링크 포함" 표시
- 네이버 지도 앱 "공유 → 복사" 글(여러 줄: [네이버 지도] / 이름 / 주소 / naver.me 링크)을 통째로 붙여넣으면 이름·주소를 나눠 읽는다.
  이름으로 못 찾으면 "주소 앞부분 + 이름"으로 한 번 더 찾는다. 입력 칸은 줄바꿈을 살리는 textarea(Enter 찾기), 화면에 "네이버 지도에서 가져오는 법" 안내

## 2026-10-02 수정: 본문을 제목 칸에 붙여넣는 문제

- 증상: 네이버 글쓰기에서 본문을 붙였더니 사진 없이 제목+본문 글자가 제목 칸에 한 줄로 들어감. 원인: 편집기를 열면 커서가 제목 칸에 있고,
  제목 칸은 평문만 받아 클립보드의 text/plain(제목+본문)이 들어간 것
- 본문 복사의 평문에서 제목을 뺌. 6단계 문구를 "제목 아래 본문 칸을 클릭하고 붙여넣기"로, 본문 복사 뒤 "제목 칸이 아니라 본문 칸에" 경고 상자
- 네이버 본문 칸이 data URI 사진을 받는지는 아직 사용자 확인 전

## 다음 작업

- 사용자: "둘 다"로 글 하나를 끝까지 써 보고 두 글이 충분히 다른지(겹침 %) 확인
- 사용자: 카카오 앱 카카오맵 사용 설정 ON → 3단계 장소 연결 확인
- 사용자: 카카오 REST API 키 발급 → 관리 › 내 티스토리 블로그에 입력 → "연결 확인" → 티스토리 카테고리에서 "가져와 학습하기"
- 사용자: 네이버·티스토리 편집기에 본문(사진 data URI 포함) 한 번에 붙여넣기가 되는지 확인(안 되면 "사진 받기"로 대체)
- 가져온 티스토리 글 중 robots·본문 추출 실패가 많으면 워커 추출기에 티스토리 본문 선택자 보강

## 2026-09-28 추가: 내 네이버 블로그 글쓰기 주소

- 관리 › "내 네이버 블로그"에 아이디(또는 블로그 주소 통째로)를 넣으면 6단계 글쓰기가 `https://blog.naver.com/{아이디}?Redirect=Write&`로 열린다
  (없으면 `GoBlogWrite.naver`). 저장: `app_settings`의 `naver.blog_id`, `GET/PUT /api/settings/naver`, `/api/user` meta `naver_blog_id`
- 기본값으로 `leejk4791`을 저장해 둠
- 6단계 흐름은 그대로: ① 제목 복사(처음 누를 때 내 블로그 편집기 열기) → ② 본문 복사(사진을 본문 안에 넣은 한 번 붙여넣기) → …
  네이버 편집기는 제목 칸과 본문 칸이 따로라 제목·본문은 각각 한 번씩 붙여넣는다. 사진이 붙는지는 실제 편집기에서 확인 필요

## 전체 개발 순서(Day 1~14) 완료 — 남은 일

1. **Anthropic 크레딧 충전 후 AI 결과 점검**: 프롬프트 6개(keyword-analysis-v1, writing-plan-v2, blog-draft-v2,
   paragraph-rewrite-v1, photo-analysis-v1) 실제 출력 확인·튜닝. 기획서 29장 평가 데이터셋(50~100건)은 이후
2. **브라우저 수동 확인**: 로그인 → 프로젝트 → 참고자료 → 분석 → 글(사진·사실) → 계획 → 초안 → 편집 → 검사 → 내보내기
3. **git 커밋 정리**(아직 커밋 없음)
4. 기획서 2차 범위: LLM 문장 근거 분류(7.2), 중복도 임베딩 검사(18.2), 사용자별 글 스타일, 다른 게시 어댑터

## 확정된 결정

- LLM 공급자 (2026-09-27): **OpenAI(GPT)와 Anthropic(Claude)을 둘 다 지원.** AI Worker에 공통 LLM Adapter
  인터페이스를 두고 두 구현을 제공한다. 작업별(prompt_templates.provider/model) 선택과 장애 시 다른 공급자로
  fallback(기획서 24장)이 가능해야 한다.
- 참고자료 수집 (2026-09-27): **네이버 글은 서버가 가져오지 않는다.** 네이버 블로그 robots.txt가
  "AI 학습·RAG 목적의 봇 접근을 엄격히 금지"한다고 명시. 카테고리 목록 자동 수집·"조회수 상위 50" 자동 수집도 하지 않음
  (조회수는 공개 데이터가 아니고, 목록 페이지는 모든 봇에 Disallow). 사용자가 URL을 넣고 본문을 붙여넣는 방식.
  네이버 외 사이트는 robots.txt를 지키며 가져온다. 참고 글 원문은 LLM에 넣지 않고 코드로 구조·통계만 뽑는다.
  자동 수집은 사용자가 약관을 검토한 뒤 별도 Source Adapter로만 추가한다. (대안으로 제안했던 북마클릿은 보류)
- 참고 글 본문 보관 (2026-09-27): **저장하지 않는다.** 특징 추출 직후 폐기. 분석 방식을 바꾸면 URL 글은 다시 가져오고,
  네이버·붙여넣기 글은 다시 붙여넣어야 한다.
- 비용 (2026-09-27): **금액은 계산하지 않고 토큰만 기록.** 실제 지출은 공급자 콘솔에서 확인. 기획서 35장 "모든 AI 호출
  비용 기록"은 "토큰 사용량 기록"으로 대체. 금액이 필요해지면 쌓인 토큰 × 단가로 사후 계산한다.
