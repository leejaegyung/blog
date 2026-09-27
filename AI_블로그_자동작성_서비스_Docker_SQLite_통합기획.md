# AI 블로그 자동 작성·업로드 서비스 --- 통합 기획/기술설계/개발명세

> 문서 버전: v1.1 --- Docker + SQLite 개인용 구성\
> 기준일: 2026-09-24\
> 목표: 사용자가 **주제(종목/키워드), 직접 촬영한 사진, 몇 줄의 사실
> 정보**만 입력하면, 해당 주제의 콘텐츠 패턴을 참고하여 **독창적인
> 네이버 블로그 초안**을 생성하고 검수 후 게시까지 연결하는 서비스

------------------------------------------------------------------------

## 1. 프로젝트 개요

### 1.1 한 줄 정의

키워드별 경쟁 콘텐츠의 구조적 특징을 분석하고, 사용자가 제공한 실제
사진·정보를 기반으로 제목/본문/사진 배치/태그/SEO 요소를 자동 생성하는
AI 블로그 제작 시스템.

### 1.2 핵심 목표

1.  사용자의 글 작성 시간을 5\~10분 수준으로 단축
2.  키워드별 상위 콘텐츠에서 **복제 대상이 아닌 패턴/구조/검색 의도**를
    추출
3.  사용자 사진과 실제 경험 정보를 글의 핵심 근거로 사용
4.  동일 문구 복제·표절을 방지하고 독창적인 원고 생성
5.  생성 → 검수 → 게시의 전 과정을 한 화면에서 처리
6.  향후 네이버 외 워드프레스/티스토리 등으로 확장 가능한 구조

### 1.3 중요 정책/구현 전제

2026-09 기준 네이버 검색 API는 검색결과를 애플리케이션에서 제공하기 위한
용도로 제한되고, 검색 결과 데이터를 임의 가공하거나 제공 목적과 다르게
사용하는 것을 제한한다. 따라서 **공식 Search API 결과를 그대로 AI
학습/경쟁글 분석 데이터로 사용하는 설계는 사전 약관 검토 없이 채택하지
않는다.**

또한 현재 공개된 네이버 Open API 목록에는 네이버 블로그 신규 글 작성용
공식 API가 명시되어 있지 않으므로, MVP에서는
`자동 생성 → 사용자 검수 → 복사/내보내기`를 기본 게시 방식으로 하고,
자동 게시 기능은 별도의 공식 연동 가능 여부와 약관을 확인한 뒤 Publisher
Adapter로 추가한다.

------------------------------------------------------------------------

## 1.4 최종 인프라 결정

이 문서의 최종 기준 구성은 다음과 같다.

``` text
Docker Compose
├─ NGINX
├─ Laravel API
├─ Vue 3
├─ SQLite
├─ Laravel Database Queue
├─ Laravel Scheduler
└─ Python FastAPI AI Worker
```

**SQLite, SQLite/파일 기반 벡터 처리, Redis는 MVP에서 제외한다.** 혼자
사용하는 서비스이므로 SQLite로 데이터와 작업 Queue를 관리하고, Docker
Volume으로 DB/사진/스토리지를 영구 보존한다.

------------------------------------------------------------------------

# 2. 사용자 시나리오

## 2.1 기본 플로우

``` text
[로그인]
   ↓
[새 글 작성]
   ↓
[키워드 입력]
예: 수원 인계동 파스타
   ↓
[분석 데이터 준비]
   ├─ 허용된 검색/트렌드 데이터
   ├─ 사용자가 직접 제공한 참고 URL
   └─ 사전에 적법하게 수집/라이선스된 분석 데이터
   ↓
[키워드 분석]
   ↓
[사진 업로드]
   ↓
[간단 정보 입력]
   ↓
[AI 글 생성]
   ↓
[SEO/중복/사실성 검사]
   ↓
[사용자 수정/승인]
   ↓
[게시용 HTML/텍스트 생성]
   ↓
[수동 게시 또는 지원되는 Publisher Adapter]
   ↓
[게시 결과/성과 기록]
```

## 2.2 사용자가 입력하는 최소 정보

-   핵심 키워드
-   업종/카테고리
-   직접 찍은 사진 3\~30장
-   장소명 또는 제품명
-   방문/사용 날짜(선택)
-   가격(선택)
-   주소(선택)
-   특징 3\~10개
-   반드시 포함할 내용
-   제외할 표현
-   말투 선택
-   글 길이 선택
-   공개 전 검수 여부

예시:

``` yaml
keyword: "수원 인계동 파스타"
category: "맛집"
place_name: "OO파스타"
facts:
  - "주차 가능"
  - "런치 세트 19,000원"
  - "봉골레가 가장 마음에 들었음"
  - "평일 오후 1시 방문"
tone: "자연스러운 후기"
length: "2500자"
```

------------------------------------------------------------------------

# 3. 핵심 기능

## 3.1 키워드 프로젝트

하나의 키워드를 하나의 분석 프로젝트로 관리한다.

예: - 수원 맛집 - 수원 인계동 파스타 - 갤럭시 S27 후기 - 서버 모니터링
솔루션 - 삼성 라이온즈 유니폼

저장 정보:

``` text
keyword
category
created_at
last_analyzed_at
analysis_version
reference_count
intent
related_keywords[]
content_patterns[]
recommended_outline[]
```

## 3.2 Top Content Analyzer

초기 목표는 키워드당 최대 50개의 참고 콘텐츠를 분석 가능한 구조로 만드는
것이다.

단, 데이터 수집은 다음 우선순위를 사용한다.

``` text
1순위: 사용자가 직접 입력한 URL/문서
2순위: 사용 권한이 확보된 데이터 공급원
3순위: 공식 API가 허용하는 범위의 검색/트렌드 메타데이터
4순위: 별도 크롤링 — 법무/약관/robots/rate-limit 검토 후 활성화
```

분석 항목:

-   제목 길이
-   핵심 키워드 위치
-   연관어
-   제목 표현 패턴
-   본문 예상/확보 글자 수
-   문단 수
-   소제목 패턴
-   사진 사용 패턴
-   도입부 유형
-   정보형/후기형/비교형 검색 의도
-   자주 등장하는 질문
-   공통 엔티티
-   장소/가격/메뉴/장단점 등 정보 슬롯
-   CTA 유형
-   태그 패턴
-   최신성
-   중복 표현 빈도

### 절대 하지 않는 것

-   상위 글 문장 그대로 재사용
-   특정 블로그의 문체를 그대로 복제
-   여러 글을 문장 단위로 짜깁기
-   원문 전체를 장기간 저장하는 것을 기본값으로 사용
-   출처 없는 사실을 AI가 임의 생성

------------------------------------------------------------------------

# 4. AI 분석 파이프라인

``` text
Keyword
   ↓
Data Source Adapter
   ↓
Reference Normalizer
   ↓
Content Parser
   ↓
Feature Extractor
   ↓
Intent Analyzer
   ↓
Pattern Aggregator
   ↓
Keyword Intelligence
   ↓
Writing Planner
   ↓
Draft Generator
   ↓
Quality Gate
   ↓
Editor
```

## 4.1 Reference Normalizer

모든 데이터 소스를 공통 포맷으로 변환한다.

``` json
{
  "source_type": "user_url",
  "url": "...",
  "title": "...",
  "published_at": "...",
  "author": null,
  "text": "...",
  "metadata": {},
  "usage_permission": "user_provided",
  "collected_at": "..."
}
```

## 4.2 Feature Extractor

LLM 호출 전에 가능한 항목은 일반 코드로 계산한다.

``` text
character_count
paragraph_count
sentence_count
keyword_frequency
keyword_position
heading_count
image_count
link_count
number_count
question_count
```

비용 절감을 위해 **정규식/형태소 분석 → 임베딩 → LLM** 순서로 처리한다.

## 4.3 Intent Analyzer

예:

``` json
{
  "primary_intent": "local_restaurant_review",
  "secondary_intents": [
    "price",
    "parking",
    "menu_recommendation"
  ],
  "must_answer": [
    "어디에 있는가?",
    "주차가 가능한가?",
    "가격은 어느 정도인가?",
    "무엇을 주문할 만한가?"
  ]
}
```

## 4.4 Pattern Aggregator

50개 문서를 각각 요약한 뒤 다시 50개를 통째로 LLM에 넣지 않는다.

권장 구조:

``` text
문서 1~10 → Batch Summary A
문서 11~20 → Batch Summary B
문서 21~30 → Batch Summary C
문서 31~40 → Batch Summary D
문서 41~50 → Batch Summary E

A~E → Keyword Intelligence
```

이 방식으로 토큰 비용과 컨텍스트 초과를 줄인다.

------------------------------------------------------------------------

# 5. 사진 분석

사용자가 업로드한 사진은 글의 가장 중요한 고유 데이터다.

## 5.1 사진 처리

``` text
Upload
 ↓
EXIF 제거 옵션
 ↓
리사이즈
 ↓
썸네일 생성
 ↓
Vision 분석
 ↓
사진 분류
 ↓
글 순서 추천
```

## 5.2 Vision 결과

``` json
{
  "image_id": 15,
  "type": "food",
  "description": "오일 파스타로 보이는 음식",
  "usable": true,
  "quality_score": 0.87,
  "suggested_section": "메인 메뉴 후기",
  "caption_hint": "..."
}
```

AI가 사진만 보고 메뉴명·가격·장소를 확정하지 않는다. 사용자가 입력한
사실 정보와 일치할 때만 확정 표현을 사용한다.

------------------------------------------------------------------------

# 6. 글 생성 엔진

## 6.1 2단계 생성

한 번의 프롬프트로 전체 글을 생성하지 않는다.

### STEP 1 --- Writing Plan

``` json
{
  "title_candidates": [],
  "search_intent": "",
  "outline": [],
  "photo_plan": [],
  "keywords": {
    "primary": [],
    "secondary": []
  },
  "required_facts": [],
  "forbidden_claims": []
}
```

### STEP 2 --- Draft

Writing Plan + 사용자 정보 + 사진 분석값만 이용해 최종 원고 생성.

## 6.2 기본 글 구조 예시

``` text
제목

도입
- 방문 이유
- 핵심 특징

사진 1

위치/접근성

사진 2~3

매장/제품 첫인상

사진 4~5

핵심 정보

사진 6~8

직접 경험/후기

장점

아쉬운 점

추천 대상

마무리

태그
```

------------------------------------------------------------------------

# 7. Quality Gate

게시 전에 자동 검사한다.

## 7.1 검사 항목

-   사용자 입력 사실 누락
-   존재하지 않는 가격/주소/메뉴 생성
-   과도한 키워드 반복
-   문장 반복
-   참고문서와 지나치게 유사한 표현
-   제목/본문 불일치
-   금지어
-   광고/협찬 표시 필요 여부
-   사진과 설명 불일치
-   과장 표현
-   개인정보 노출
-   글 길이
-   사진 미사용

## 7.2 Hallucination Gate

모든 사실 문장을 다음 세 종류로 구분한다.

``` text
VERIFIED_USER_FACT
REFERENCE_SUPPORTED
GENERATIVE_OPINION
```

확인되지 않은 구체적 사실은 게시 전 경고한다.

------------------------------------------------------------------------

# 8. 추천 기술 스택

사용자의 기존 개발 경험을 고려하면 **Laravel + Vue + SQLite** 조합을
유지하면서 AI/수집 워커만 Python으로 분리하는 구성이 효율적이다.

## Frontend

``` text
Vue 3
TypeScript
Vite
Pinia
Vue Router
Tailwind CSS
TipTap Editor
Axios
```

## Main Backend

``` text
Laravel 12
PHP 8.4+
Laravel Sanctum
Laravel Queue
Laravel Scheduler
Redis
```

## AI / Analyzer Worker

``` text
Python 3.12+
FastAPI
Pydantic
httpx
BeautifulSoup4
trafilatura
Playwright (허용된 사이트/업무에 한해)
KoNLPy 또는 Kiwi
OpenAI SDK 또는 교체 가능한 LLM Adapter
```

## Database

``` text
SQLite 3
Laravel Eloquent ORM
SQLite FTS5 (선택)
```

DB 파일은 Docker named volume 또는 호스트 bind mount에 영구 저장한다.

권장 위치:

``` text
/data/database/blog-ai.sqlite
```

개인용 MVP에서는 SQLite/SQLite/파일 기반 벡터 처리를 사용하지 않는다.
의미 기반 유사도 검사가 필요한 경우 임베딩 벡터를 JSON 또는 별도 파일로
저장하고 Python Worker에서 cosine similarity를 계산한다.

## Cache / Queue

MVP에서는 Redis를 사용하지 않는다.

``` text
Laravel Cache: database 또는 file
Laravel Queue: database driver
Laravel Session: database 또는 file
Laravel Scheduler: 별도 scheduler container
```

향후 동시 작업량이 많아질 때만 Redis를 선택적으로 추가한다.

## Storage

개발:

``` text
Local Storage / MinIO
```

운영:

``` text
S3 Compatible Object Storage
```

## Infra

``` text
Docker
Docker Compose
NGINX
Ubuntu / Rocky Linux / Windows + Docker Desktop
GitHub Actions (선택)
```

Docker Compose 하나로 Web, API, AI Worker, Queue Worker, Scheduler를
실행한다.

## Monitoring

``` text
Prometheus
Grafana
Loki
Sentry
```

------------------------------------------------------------------------

# 9. 시스템 아키텍처

``` text
                         Docker Compose
┌──────────────────────────────────────────────────────────┐
│                                                          │
│  ┌────────────┐      ┌───────────────────────────────┐   │
│  │   NGINX    │ ───▶ │ Vue + Laravel API             │   │
│  │   :80      │      │ app container                 │   │
│  └────────────┘      └───────────┬───────────────────┘   │
│                                  │                       │
│                     ┌────────────▼────────────┐          │
│                     │ SQLite                 │          │
│                     │ /data/blog-ai.sqlite   │          │
│                     └─────────────────────────┘          │
│                                  │                       │
│  ┌─────────────────┐   ┌────────▼────────┐              │
│  │ Laravel Queue   │   │ Laravel         │              │
│  │ database driver │   │ Scheduler       │              │
│  └────────┬────────┘   └─────────────────┘              │
│           │                                              │
│  ┌────────▼──────────────────────────────────────────┐   │
│  │ Python / FastAPI AI Worker                       │   │
│  │ Parser / NLP / Vision / LLM / Quality Gate      │   │
│  └───────────────────────────────────────────────────┘   │
│                                                          │
│  Volumes                                                 │
│  ├─ sqlite-data  → DB                                    │
│  ├─ uploads      → 사용자 사진                           │
│  └─ app-storage  → 로그/캐시/생성 결과                  │
└──────────────────────────────────────────────────────────┘
```

### 컨테이너 구성

``` text
nginx
app
queue
scheduler
ai-worker
```

SQLite는 별도 DB 컨테이너가 필요하지 않는다. `app`, `queue`,
`scheduler`가 동일한 SQLite 볼륨을 공유한다.

주의: SQLite는 다중 쓰기 동시성이 높은 서비스에는 적합하지 않지만, 본
프로젝트처럼 **1인 사용 + 낮은 동시 작업량**에는 충분하다. WAL 모드를
활성화한다.

``` sql
PRAGMA journal_mode=WAL;
PRAGMA busy_timeout=5000;
```

------------------------------------------------------------------------

# 10. 모듈 구조

``` text
apps/
├── web/
│   └── Vue
├── api/
│   └── Laravel
└── ai-worker/
    └── FastAPI

services/
├── source-adapter
├── analyzer
├── vision
├── generator
├── quality-gate
└── publisher

infra/
├── nginx
├── docker
└── monitoring

data/
├── database
├── uploads
└── storage
```

------------------------------------------------------------------------

# 11. 데이터베이스 설계

## users

``` text
id
name
email
password
plan
created_at
```

## blogs

``` text
id
user_id
platform
blog_name
blog_url
publish_mode
status
```

## keyword_projects

``` text
id
user_id
keyword
category
status
last_analyzed_at
analysis_version
created_at
```

## reference_documents

``` text
id
keyword_project_id
source_type
source_url
title
author
published_at
raw_storage_key
content_hash
usage_permission
collected_at
```

## document_features

``` text
id
reference_document_id
char_count
paragraph_count
image_count
keyword_frequency
features_json
```

## keyword_analyses

``` text
id
keyword_project_id
primary_intent
related_keywords_json
common_topics_json
recommended_outline_json
title_patterns_json
must_answer_json
created_at
```

## posts

``` text
id
user_id
keyword_project_id
title
content_json
content_html
content_text
status
generation_version
published_url
published_at
created_at
updated_at
```

## post_facts

``` text
id
post_id
fact_key
fact_value
source_type
verified
```

## post_images

``` text
id
post_id
storage_key
sort_order
vision_json
caption
```

## generations

``` text
id
post_id
model
prompt_version
input_tokens
output_tokens
cost
latency_ms
created_at
```

## publish_jobs

``` text
id
post_id
publisher
status
attempt
error_message
created_at
finished_at
```

------------------------------------------------------------------------

# 12. 주요 API

## 프로젝트

``` http
POST   /api/projects
GET    /api/projects
GET    /api/projects/{id}
POST   /api/projects/{id}/analyze
GET    /api/projects/{id}/analysis
```

## 참고자료

``` http
POST   /api/projects/{id}/references
DELETE /api/references/{id}
POST   /api/references/{id}/parse
```

## 이미지

``` http
POST   /api/posts/{id}/images
DELETE /api/posts/{id}/images/{imageId}
POST   /api/posts/{id}/images/analyze
PATCH  /api/posts/{id}/images/order
```

## 글

``` http
POST   /api/posts
POST   /api/posts/{id}/plan
POST   /api/posts/{id}/generate
POST   /api/posts/{id}/regenerate-section
POST   /api/posts/{id}/quality-check
PATCH  /api/posts/{id}
```

## 게시

``` http
POST /api/posts/{id}/publish
GET  /api/posts/{id}/publish-status
POST /api/posts/{id}/export
```

------------------------------------------------------------------------

# 13. Queue Job

시간이 오래 걸리는 작업은 HTTP 요청 안에서 처리하지 않는다. Redis 대신
Laravel의 **database queue driver**를 사용한다.

``` text
AnalyzeKeywordJob
FetchAuthorizedReferenceJob
ParseReferenceJob
AnalyzeReferenceJob
AggregateKeywordJob
AnalyzeImageJob
GeneratePlanJob
GeneratePostJob
QualityCheckJob
PublishPostJob
CollectPerformanceJob
```

SQLite에 Laravel `jobs`, `job_batches`, `failed_jobs` 테이블을 생성한다.

``` bash
php artisan make:queue-table
php artisan make:queue-batches-table
php artisan make:queue-failed-table
php artisan migrate
```

Queue Worker는 Docker에서 별도 컨테이너로 실행한다.

``` text
queue:
  php artisan queue:work --sleep=2 --tries=3 --timeout=300
```

1인용이므로 초기에는 queue 하나만 운영한다. 이미지/분석 작업이 무거워질
경우에만 queue 이름을 분리한다.

------------------------------------------------------------------------

# 14. AI Prompt 관리

프롬프트를 소스코드에 하드코딩하지 않는다.

``` text
prompt_templates
- id
- name
- version
- system_prompt
- user_template
- model
- temperature
- enabled
```

버전 예:

``` text
keyword-analysis-v1
writing-plan-v1
blog-generator-v1
quality-check-v1
```

생성된 글마다 사용한 prompt/model/version을 기록하여 결과 재현이
가능해야 한다.

------------------------------------------------------------------------

# 15. 에디터 UX

화면은 3열 또는 2열 구조를 권장한다.

``` text
┌─────────────┬────────────────────────┬──────────────┐
│ 입력 정보    │       글 Editor        │ AI Assistant │
│             │                        │              │
│ 키워드       │ 제목                   │ 제목 다시쓰기 │
│ 장소         │ 본문                   │ 문단 확장      │
│ 가격         │ 사진                   │ 짧게           │
│ 특징         │ 본문                   │ 자연스럽게     │
│ 사진         │ ...                    │ SEO 검사       │
└─────────────┴────────────────────────┴──────────────┘
```

필수 기능:

-   Drag & Drop 사진 순서 변경
-   AI 추천 사진 위치
-   제목 후보 5개
-   선택 문단만 재작성
-   말투 변경
-   길이 조절
-   Undo/Redo
-   자동 저장
-   모바일 미리보기
-   네이버 게시 형태에 가까운 Preview
-   생성 전/후 Diff

------------------------------------------------------------------------

# 16. 분석 대시보드

키워드 분석 화면:

``` text
Keyword: 수원 인계동 파스타

검색 의도
[맛집 후기 65%]
[메뉴 정보 20%]
[위치/주차 15%]

자주 다뤄지는 주제
1. 위치
2. 주차
3. 메뉴
4. 가격
5. 분위기
6. 대표 메뉴
7. 재방문 의사

추천 글 구성
도입 → 위치 → 매장 → 메뉴 → 음식 → 장단점 → 총평

관련 표현
인계동 / 수원시청역 / 파스타 / 데이트 / 주차 / 런치
```

중요: 이 점수는 **네이버 검색 순위 예측 점수**가 아니라 확보된
참고자료의 분포를 표현하는 내부 분석값이다.

------------------------------------------------------------------------

# 17. 게시 모듈 설계

Publisher를 인터페이스화한다.

``` php
interface Publisher
{
    public function validate(Post $post): PublishValidation;
    public function publish(Post $post): PublishResult;
}
```

구현:

``` text
ManualExportPublisher
WordPressPublisher
TistoryPublisher
NaverPublisher   // 공식적이고 허용된 게시 방식 확보 시 활성화
```

MVP의 네이버 흐름:

``` text
AI 생성
 → 검수
 → 네이버용 포맷 변환
 → 사진 순서/캡션 표시
 → Copy 또는 Export
 → 사용자가 네이버 에디터에서 최종 게시
```

브라우저 로그인 세션/쿠키를 서버에 저장해 무단 자동화하는 방식은 기본
설계에서 제외한다.

------------------------------------------------------------------------

# 18. 중복/표절 방지

## 18.1 생성 전

참고문서는 **사실/검색 의도/구조 통계**로 변환하고 원문 문장을 생성
프롬프트에 대량 전달하지 않는다.

## 18.2 생성 후

``` text
문장 n-gram 비교
Embedding similarity
반복 문장 검사
금칙 문구 검사
```

임계치 초과 문단은 자동 재생성한다.

예:

``` text
similarity > configured_threshold
    → rewrite
    → recheck
```

임계값은 실제 검증 데이터로 튜닝하며 임의의 "네이버 최적화 점수"로
표현하지 않는다.

------------------------------------------------------------------------

# 19. SEO/콘텐츠 점검 점수

서비스 내부 품질점수 예:

``` text
Content Quality 100
├─ 검색 의도 충족       20
├─ 사용자 사실 반영     20
├─ 구조 완성도          15
├─ 사진 활용            15
├─ 가독성               10
├─ 제목/본문 일치       10
└─ 중복/위험 표현       10
```

이 점수는 네이버 공식 점수 또는 상위 노출 보장 점수가 아니다.

------------------------------------------------------------------------

# 20. 보안

## 필수

-   HTTPS
-   Password Hash
-   CSRF/XSS 방어
-   SQL Injection 방어
-   MIME 검증
-   업로드 크기 제한
-   이미지 재인코딩
-   EXIF 제거 옵션
-   API Key 암호화
-   Secret은 `.env`/Secret Manager
-   Rate Limit
-   Audit Log
-   관리자 2FA
-   사용자별 Object Storage Prefix
-   Signed URL

## 절대 저장 금지

``` text
네이버 사용자 비밀번호
평문 Access Token
브라우저 쿠키의 무기한 저장
```

------------------------------------------------------------------------

# 21. 비용 최적화

50개 참고자료를 매번 재분석하면 비용이 증가한다.

## Cache Key

``` text
keyword + source_hash + analyzer_version
```

동일 문서는 재분석하지 않는다.

## 분석 전략

``` text
1. 코드 기반 특징 추출
2. 저비용 모델로 개별 요약
3. 임베딩/클러스터링
4. 대표 데이터만 고성능 모델 분석
5. 최종 글 생성에 고성능 모델 사용
```

키워드 분석 결과에는 TTL을 둔다.

예:

``` text
일반 키워드: 7일
트렌드 키워드: 1일
사용자 수동 갱신: 즉시
```

------------------------------------------------------------------------

# 22. 운영 관리자

관리자 메뉴:

``` text
Dashboard
Users
Projects
Keyword Analysis
Reference Sources
Posts
AI Usage
Token Cost
Prompt Versions
Publish Jobs
Error Logs
Policy/Blocked Sources
System Settings
```

대시보드 KPI:

``` text
DAU
생성 글 수
게시 완료 수
평균 생성시간
평균 AI 비용
실패율
재생성률
사용자 수정률
키워드 분석 Cache Hit
```

------------------------------------------------------------------------

# 23. 로그

구조화 로그를 사용한다.

``` json
{
  "trace_id": "...",
  "user_id": 10,
  "post_id": 150,
  "job": "GeneratePostJob",
  "model": "...",
  "latency_ms": 4300,
  "status": "success"
}
```

AI 프롬프트 원문에 개인정보가 포함될 수 있으므로 운영 로그에는 필요
최소한만 기록한다.

------------------------------------------------------------------------

# 24. 장애 대응

## LLM 장애

``` text
Retry 3회
↓
Exponential Backoff
↓
Fallback Model
↓
실패 Queue
↓
관리자 Alert
```

## Reference Source 장애

하나의 Adapter 장애가 전체 시스템 장애가 되지 않도록 한다.

``` text
SourceAdapterInterface
├── UserDocumentAdapter
├── LicensedDataAdapter
├── SearchMetadataAdapter
└── FutureAdapter
```

------------------------------------------------------------------------

# 24.1 Docker Compose 설계

권장 서비스 구성:

``` yaml
services:
  nginx:
    image: nginx:alpine
    ports:
      - "8080:80"
    depends_on:
      - app

  app:
    build: ./apps/api
    volumes:
      - sqlite-data:/data/database
      - uploads:/data/uploads
      - app-storage:/var/www/html/storage
    environment:
      DB_CONNECTION: sqlite
      DB_DATABASE: /data/database/blog-ai.sqlite
      QUEUE_CONNECTION: database
      AI_WORKER_URL: http://ai-worker:8000

  queue:
    build: ./apps/api
    command: php artisan queue:work --sleep=2 --tries=3 --timeout=300
    volumes:
      - sqlite-data:/data/database
      - uploads:/data/uploads
      - app-storage:/var/www/html/storage
    depends_on:
      - app
      - ai-worker

  scheduler:
    build: ./apps/api
    command: sh -c "while true; do php artisan schedule:run --verbose --no-interaction; sleep 60; done"
    volumes:
      - sqlite-data:/data/database
      - uploads:/data/uploads
      - app-storage:/var/www/html/storage

  ai-worker:
    build: ./apps/ai-worker
    expose:
      - "8000"
    volumes:
      - uploads:/data/uploads

volumes:
  sqlite-data:
  uploads:
  app-storage:
```

실제 개발 시 Vue를 Laravel과 함께 빌드할 수도 있고, 개발 편의를 위해
`web` 컨테이너를 별도로 둘 수도 있다.

### 실행

``` bash
docker compose build
docker compose up -d
docker compose ps
```

### SQLite 초기화

컨테이너 시작 시 DB 파일이 없으면 생성한다.

``` bash
mkdir -p /data/database
touch /data/database/blog-ai.sqlite
php artisan migrate --force
```

### 백업

개인용에서는 SQLite의 단일 파일 백업이 큰 장점이다.

권장:

``` text
/data/database/blog-ai.sqlite
/data/uploads/
/data/storage/
```

DB 백업 시 실행 중인 DB 파일을 단순 복사하기보다는 SQLite backup 명령
또는 Laravel 백업 작업을 사용한다.

### Docker 운영 목표

``` text
git clone
   ↓
.env 작성
   ↓
docker compose up -d --build
   ↓
migration
   ↓
http://localhost:8080
```

즉 새 PC에서도 Docker만 설치되어 있으면 서비스 전체를 바로 올릴 수
있도록 한다.

------------------------------------------------------------------------

# 25. 개발 단계

## Phase 0 --- PoC / 1주

목표: - 키워드 1개 - 참고 URL 수동 입력 - 10\~20개 분석 - 사진 업로드 -
AI 초안 생성

완료 기준: 사용자가 `키워드 + 사진 + 사실 5개`만 입력해 실제 게시 가능한
초안을 얻는다.

## Phase 1 --- MVP / 3\~5주

-   회원
-   프로젝트
-   키워드 분석
-   최대 50개 Reference
-   이미지 관리
-   Vision
-   Writing Plan
-   글 생성
-   TipTap Editor
-   Quality Gate
-   네이버용 Copy/Export
-   Queue
-   관리자 AI 비용 확인

## Phase 2 --- Beta / 3\~4주

-   다중 키워드
-   분석 Cache
-   SQLite 기반 임베딩 메타데이터 저장
-   중복도 검사
-   제목 A/B 후보
-   Prompt 관리
-   통계
-   게시 Adapter 확장
-   사용자별 글 스타일 Profile

## Phase 3 --- Production / 4\~6주

-   결제/요금제
-   팀 계정
-   대량 생성
-   스케줄
-   모니터링
-   장애 복구
-   Backup
-   CDN
-   Auto Scaling
-   성과 데이터 분석

1인 개발 기준 MVP는 약
**4~7주**, 운영 가능한 SaaS는 기능 범위에 따라 약 **2~4개월**을 현실적인
1차 범위로 잡는다.

------------------------------------------------------------------------

# 26. 개발 우선순위

``` text
P0
- 사용자 입력
- 이미지 업로드
- Reference 분석
- Keyword Intelligence
- AI Writing Plan
- 글 생성
- Editor
- Export

P1
- Vision
- Quality Gate
- 중복 검사
- Prompt Version
- Queue
- Cache
- 관리자

P2
- 자동 게시 Adapter
- 성과 분석
- 예약 게시
- 결제
- 팀 기능
```

------------------------------------------------------------------------

# 27. 프로젝트 폴더 예시

``` text
blog-ai/
├── apps/
│   ├── api/
│   │   ├── app/
│   │   ├── routes/
│   │   └── database/
│   ├── web/
│   │   ├── src/
│   │   └── public/
│   └── ai-worker/
│       ├── app/
│       │   ├── api/
│       │   ├── adapters/
│       │   ├── analyzers/
│       │   ├── generators/
│       │   ├── quality/
│       │   └── vision/
│       └── tests/
├── prompts/
├── infra/
│   ├── nginx/
│   └── monitoring/
├── data/
│   ├── database/
│   ├── uploads/
│   └── storage/
├── docs/
├── .env
└── docker-compose.yml
```

------------------------------------------------------------------------

# 28. 환경변수

``` dotenv
APP_ENV=production
APP_KEY=

DB_CONNECTION=sqlite
DB_DATABASE=/data/database/blog-ai.sqlite

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

FILESYSTEM_DISK=local
UPLOAD_PATH=/data/uploads

AI_WORKER_URL=http://ai-worker:8000
LLM_API_KEY=

NAVER_CLIENT_ID=
NAVER_CLIENT_SECRET=

SENTRY_DSN=
```

API Key는 Frontend에 절대 전달하지 않는다.

------------------------------------------------------------------------

# 29. 테스트 전략

## Unit Test

-   Feature extractor
-   Keyword normalizer
-   HTML sanitizer
-   Quality rules
-   Publisher formatter

## Integration Test

-   Laravel ↔ SQLite Queue
-   Laravel ↔ AI Worker
-   AI Worker ↔ Object Storage
-   DB transaction
-   Queue retry

## AI Evaluation Dataset

최소 50\~100개의 테스트 케이스를 별도 관리한다.

``` text
keyword
user_facts
photos
expected_required_facts
forbidden_claims
expected_outline
```

평가:

``` text
Fact Recall
Unsupported Claim Rate
Repetition
Structure Coverage
User Edit Distance
Generation Latency
Generation Cost
```

------------------------------------------------------------------------

# 30. MVP 화면

``` text
/login

/dashboard

/projects
/projects/create
/projects/{id}
/projects/{id}/analysis

/posts/create
/posts/{id}/edit
/posts/{id}/preview

/settings/blog
/settings/ai

/admin
```

------------------------------------------------------------------------

# 31. 첫 화면 작성 Wizard

### 1단계

``` text
어떤 글을 작성할까요?
[ 수원 인계동 파스타 ]
```

### 2단계

``` text
사진을 올려주세요.
[ Drag & Drop ]
```

### 3단계

``` text
알려주고 싶은 내용을 적어주세요.

장소:
가격:
좋았던 점:
아쉬웠던 점:
꼭 넣을 내용:
```

### 4단계

``` text
스타일

○ 자연스러운 후기
○ 전문 정보형
○ 친근한 말투
○ 깔끔한 정보형

길이
○ 짧게
● 보통
○ 길게
```

### 5단계

``` text
[ 분석 후 글 생성 ]
```

------------------------------------------------------------------------

# 32. 서비스 차별화 포인트

단순한 "ChatGPT 글쓰기 UI"로 만들면 경쟁력이 약하다.

핵심 자산은 다음 5개다.

``` text
Keyword Intelligence
+
User Fact Grounding
+
Photo Intelligence
+
Quality Gate
+
Performance Feedback
```

즉, **상위 글을 베끼는 서비스가 아니라 해당 검색어에서 사람들이 어떤
정보를 원하는지 파악하고, 사용자의 실제 경험을 그 구조에 맞게 잘
표현하는 서비스**로 정의한다.

------------------------------------------------------------------------

# 33. 장기 확장

``` text
네이버 블로그
↓
WordPress
↓
Tistory
↓
Instagram Caption
↓
Threads
↓
YouTube Description
↓
Short-form Script
```

한 번 입력한 사진/정보를 Channel Adapter를 통해 여러 콘텐츠로 변환한다.

------------------------------------------------------------------------

# 34. 추천 MVP 최종 범위

첫 버전에서는 욕심을 줄여 아래까지만 완성한다.

``` text
1. 로그인
2. 키워드 프로젝트 생성
3. 사용자가 참고 URL 최대 50개 등록
4. 참고 콘텐츠 분석
5. 키워드 Intelligence 생성
6. 사진 다중 업로드
7. 사진 AI 분석
8. 사실 정보 입력
9. 제목 5개 생성
10. 글 Outline 생성
11. 최종 본문 생성
12. 문단별 재생성
13. Quality Gate
14. 네이버 Preview
15. Copy / HTML / TXT Export
16. 생성 비용/로그 관리자 화면
```

이 버전이 실제 사용 가능해진 뒤 공식적으로 허용되는 데이터 수집 자동화와
게시 자동화를 순차적으로 붙이는 것이 가장 안전하다.

------------------------------------------------------------------------

# 35. 완료 정의 (Definition of Done)

MVP 완료는 다음 조건을 모두 만족할 때로 정의한다.

-   사용자가 키워드 1개를 입력할 수 있다.
-   참고자료 최대 50개를 분석할 수 있다.
-   사진 20장 이상을 안정적으로 업로드할 수 있다.
-   사용자가 3\~5개의 사실만 입력해도 초안이 생성된다.
-   사용자 입력 사실이 결과에 반영된다.
-   확인되지 않은 구체적 사실 생성률을 자동 평가한다.
-   제목 후보가 생성된다.
-   사진 배치가 추천된다.
-   문단별 재생성이 가능하다.
-   자동 저장된다.
-   게시 전 Quality Gate가 동작한다.
-   네이버에 붙여넣기 쉬운 형태로 Export된다.
-   모든 AI 호출 비용/모델/버전이 기록된다.
-   SQLite database queue의 실패 작업이 재처리 가능하다.
-   운영 로그로 장애 추적이 가능하다.

------------------------------------------------------------------------

# 36. 개발 시작 순서

``` text
Day 1
Repository / Docker Compose / Laravel / Vue / SQLite

Day 2
Auth / Project / Post DB

Day 3
Image Upload / Object Storage

Day 4
FastAPI AI Worker

Day 5
Reference Parser

Day 6
Feature Extractor

Day 7
Keyword Intelligence

Day 8
Writing Plan

Day 9
Draft Generator

Day 10
Editor

Day 11
Vision

Day 12
Quality Gate

Day 13
Export / Preview

Day 14
Queue / Retry / Logging

Day 15+
실사용 테스트 → Prompt/Eval 개선
```

------------------------------------------------------------------------

# 37. 최종 제품 방향

이 프로젝트의 중심은 "자동 블로그 작성" 자체가 아니다.

``` text
검색 의도 분석
      +
사용자 고유 정보
      +
사용자 직접 촬영 사진
      +
AI 편집
      +
검증
      =
고유한 블로그 콘텐츠
```

이 구조로 시작하면 추후 특정 플랫폼 정책이나 AI 모델이 변경되더라도
Source Adapter / LLM Adapter / Publisher Adapter만 교체하여 전체
서비스를 유지할 수 있다.
