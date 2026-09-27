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
