# Makefile for Short Link Bundle Demo - Symfony 8
# FrankenPHP + Caddyfile. Run from this directory: make up, make install, etc.

COMPOSE_FILE := docker-compose.yml
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down restart build install shell logs setup db-schema cache-clear update-bundle update-deps ensure-up test test-coverage verify test-e2e demo-screenshots

help:
	@echo "Short Link Bundle Demo - Symfony 8"
	@echo ""
	@echo "  make up           Start containers (FrankenPHP on port \$${PORT:-8231})"
	@echo "  make down         Stop containers"
	@echo "  make build        Build image (no cache)"
	@echo "  make install      Install dependencies in container"
	@echo "  make setup        install + database"
	@echo "  make shell        Open shell in PHP container"
	@echo "  make logs         Show container logs"
	@echo "  make db-schema    Update schema"
	@echo "  make cache-clear  Clear Symfony cache"
	@echo "  make update-bundle Update bundle from path repo"
	@echo "  make update-deps  composer update in a one-off container (REQ-MAKE-008)"
	@echo "  make restart      Restart containers"
	@echo "  make test         Lint the container and the Twig templates"
	@echo "  make test-coverage Same as test (the demo has no PHPUnit suite)"
	@echo "  make verify       HTTP 200 on / (demo must be up)"
	@echo "  make test-e2e     Playwright e2e against the running demo (official Playwright image, REQ-DEMO-013)"
	@echo "  make demo-screenshots Refresh docs/images/demo/*.webp from the running demo"
	@echo ""

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@echo "Waiting…"
	$(COMPOSE) up -d
	@echo "Waiting for container to be ready..."
	@sleep 5
	@# The entrypoint runs the first composer install and the schema, then starts FrankenPHP: wait for the server
	@# instead of racing that install on the shared vendor/ (up to 15 minutes on a cold cache).
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); [ -z "$$PORT" ] && PORT=8231; \
	i=0; while [ $$i -lt 300 ] && [ "$$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$$PORT 2>/dev/null)" = "000" ]; do i=$$((i+1)); sleep 3; done
	@echo "Installing dependencies..."
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction || true
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8231; \
	code=000; \
	i=0; \
	while [ $$i -lt 90 ]; do \
		code=$$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$$PORT 2>/dev/null || echo "000"); \
		if [ "$$code" = "200" ] || [ "$$code" = "302" ]; then break; fi; \
		i=$$((i+1)); sleep 2; \
	done; \
	if [ "$$code" != "200" ] && [ "$$code" != "302" ]; then \
		echo "Demo not ready (HTTP $$code)"; \
		$(COMPOSE) logs --tail=80 db $(SERVICE_PHP) || true; \
		exit 1; \
	fi; \
	echo "Demo started at: http://localhost:$$PORT"

down:
	$(COMPOSE) down

restart: ensure-up
	$(COMPOSE) restart

build:
	$(COMPOSE) build --no-cache

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container (demo $(COMPOSE))..."; \
		$(COMPOSE) up -d; \
		sleep 5; \
		$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction; \
	fi

install: ensure-up
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction

setup: install db-schema
	@echo "Demo ready. App: http://localhost:$${PORT:-8231}"

db-schema: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:create --if-not-exists --no-interaction || true

shell: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) sh

logs:
	$(COMPOSE) logs -f

cache-clear: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console cache:clear --env=dev --no-warmup || true

update-bundle: ensure-up
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer update nowo-tech/short-link-bundle --no-interaction

# REQ-MAKE-003: the demo has no PHPUnit suite (tests/ only holds the bootstrap); lint the wiring and templates instead.
test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console lint:container
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console lint:twig templates

# REQ-MAKE-003: the demo has no PHPUnit suite, so coverage is the same lint.
test-coverage: test

# REQ-MAKE-003: final check, HTTP 200 on / of the running demo.
verify:
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); [ -z "$$PORT" ] && PORT=8231; \
	code=$$(curl -sL -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/" 2>/dev/null || echo 000); \
	if [ "$$code" != "200" ]; then echo "verify failed (HTTP $$code)"; exit 1; fi; echo "verify OK (HTTP $$code)"

# REQ-DEMO-013: Playwright in the official image (no Node on the host); the demo must be up (make up).
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v1.64.0-jammy

test-e2e: verify
	@mkdir -p e2e/node_modules
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); PORT=$${PORT:-8231}; \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e CI="$${CI:-}" \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=chromium'

demo-screenshots: verify
	@mkdir -p ../../docs/images/demo e2e/node_modules
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); PORT=$${PORT:-8231}; \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" \
		-v "$(CURDIR)/../../docs/images/demo:/screenshots" \
		-w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		-e SCREENSHOT_DIR=/screenshots \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=screenshots && node scripts/to-webp.mjs /screenshots'
	@echo "Screenshots written under docs/images/demo/ (WebP)"

# REQ-MAKE-008: update-deps (soft include; the shared script exists in the bundles workspace, not on a CI checkout).
-include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
