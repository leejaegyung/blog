# 로컬에 PHP/Python이 없어도 되도록 테스트는 전부 컨테이너에서 실행한다.
API_TOOL = docker run --rm -v "$(CURDIR)/apps/api":/app -w /app composer:2
PY_TOOL  = docker run --rm -v "$(CURDIR)/apps/ai-worker":/srv -w /srv -e UV_PROJECT_ENVIRONMENT=/tmp/venv ghcr.io/astral-sh/uv:0.8-python3.12-bookworm-slim

.PHONY: up down logs test test-api test-worker test-web check smoke artisan composer base-images

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f --tail 50

test: test-api test-worker test-web

test-api:
	$(API_TOOL) php artisan test

test-worker:
	$(PY_TOOL) uv run pytest -q

test-web:
	cd apps/web && npx vitest run && npm run type-check && npm run lint

# 완료 선언 전 필수 게이트: 전체 테스트 + 스택 재빌드 + 헬스체크
check: test up smoke

smoke:
	@for i in $$(seq 1 30); do curl -fsS -o /dev/null http://localhost:$${APP_PORT:-8080}/api/health && break; sleep 1; done
	@curl -fsS http://localhost:$${APP_PORT:-8080}/api/health && echo
	@curl -fsS -o /dev/null http://localhost:$${APP_PORT:-8080}/ && echo "SPA ok"

# 예: make artisan c="make:model Post -m"
artisan:
	$(API_TOOL) php artisan $(c)

# 예: make composer c="require foo/bar"
composer:
	$(API_TOOL) composer $(c)

# Docker 엔진이 레지스트리에 못 붙을 때(빌드가 "load metadata ... DeadlineExceeded") 베이스 이미지를 컨테이너로 받아 넣는다
BASE_IMAGES = php:8.4-fpm-alpine composer:2 node:24-alpine nginx:1.29-alpine python:3.12-slim
base-images:
	@arch=$$(docker info --format '{{.Architecture}}' | sed 's/aarch64/arm64/;s/x86_64/amd64/'); \
	for image in $(BASE_IMAGES); do \
		if docker image inspect $$image >/dev/null 2>&1; then echo "있음 $$image"; continue; fi; \
		repo=$${image%%:*}; tag=$${image#*:}; \
		docker run --rm -v "$(CURDIR)/infra/scripts":/s -v /tmp:/out python:3.12-slim \
			python /s/fetch_base_image.py $$repo $$tag /out/$$repo.tar $$arch && docker load -i /tmp/$$repo.tar && rm -f /tmp/$$repo.tar; \
	done
