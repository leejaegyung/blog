# 로컬에 PHP/Python이 없어도 되도록 테스트는 전부 컨테이너에서 실행한다.
API_TOOL = docker run --rm -v "$(CURDIR)/apps/api":/app -w /app composer:2
PY_TOOL  = docker run --rm -v "$(CURDIR)/apps/ai-worker":/srv -w /srv -e UV_PROJECT_ENVIRONMENT=/tmp/venv ghcr.io/astral-sh/uv:0.8-python3.12-bookworm-slim

.PHONY: up down logs test test-api test-worker test-web check smoke artisan composer base-images claude-bridge claude-bridge-token claude-bridge-install claude-bridge-uninstall

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

# Claude 구독(Claude Code)으로 글쓰기 — API 크레딧 없이 이 Mac에 로그인된 claude를 쓴다(infra/claude-bridge)
BRIDGE_PLIST = $(HOME)/Library/LaunchAgents/com.blog-ai.claude-bridge.plist

# 토큰이 없으면 만들고, 컨테이너가 새 토큰을 읽도록 다시 띄운다
claude-bridge-token:
	@grep -qE '^CLAUDE_BRIDGE_TOKEN=.+' .env || { \
		echo "CLAUDE_BRIDGE_TOKEN=$$(python3 -c 'import secrets; print(secrets.token_urlsafe(32))')" >> .env; \
		docker compose up -d app queue scheduler ai-worker; }

# 앞에서 실행(창을 닫으면 멈춤)
claude-bridge: claude-bridge-token
	python3 infra/claude-bridge/bridge.py

# 로그인하면 자동으로 뒤에서 실행되게 등록. macOS가 launchd 프로그램의 데스크톱 폴더 접근을 막으므로
# 연결기와 토큰을 ~/.blog-ai 로 복사해 거기서 실행한다(bridge.py를 고치면 다시 실행). 로그: ~/.blog-ai/claude-bridge.log
BRIDGE_HOME = $(HOME)/.blog-ai
claude-bridge-install: claude-bridge-token
	@mkdir -p $(BRIDGE_HOME) $(HOME)/Library/LaunchAgents
	@cp infra/claude-bridge/bridge.py $(BRIDGE_HOME)/bridge.py
	@grep -E '^CLAUDE_BRIDGE_(TOKEN|PORT|PARALLEL|TIMEOUT)=' .env > $(BRIDGE_HOME)/bridge.env && chmod 600 $(BRIDGE_HOME)/bridge.env
	@sed -e "s#__HOME__#$(BRIDGE_HOME)#g" -e "s#__PYTHON__#$$(command -v python3)#g" -e "s#__PATH__#$$PATH#g" \
		infra/claude-bridge/com.blog-ai.claude-bridge.plist > $(BRIDGE_PLIST)
	@launchctl bootout gui/$$(id -u) $(BRIDGE_PLIST) 2>/dev/null || true
	@launchctl bootstrap gui/$$(id -u) $(BRIDGE_PLIST) && echo "claude-bridge 등록됨 (끄기: make claude-bridge-uninstall)"

claude-bridge-uninstall:
	@launchctl bootout gui/$$(id -u) $(BRIDGE_PLIST) 2>/dev/null || true
	@rm -f $(BRIDGE_PLIST) && echo "claude-bridge 등록 해제됨"
