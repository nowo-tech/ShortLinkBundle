# Makefile for Short Link Bundle
# All dev targets use the root docker-compose.yml.

COMPOSE_FILE := docker-compose.yml
# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
# Resolve `docker` via `command -v` so a local `docker/` directory cannot shadow the CLI when PATH has empty components.
DOCKER_BIN  := $(shell command -v docker 2>/dev/null || echo docker)
COMPOSE_BIN := $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down down-dev build shell install ensure-up test-e2e demo-screenshots test test-coverage test-coverage-100 coverage-check validate-phpdoc coverage-php-percent cs-check cs-fix qa clean release-check release-check-demos demo-smoke composer-sync rector rector-dry phpstan igor update validate assets setup-hooks check-no-cursor-coauthor check-open-prs check-no-raw-html-form check-twig-extra validate-translations db-up test-with-db test-coverage-with-db update-deps strip-cursor-coauthor-from-history

help:
	@echo "Short Link Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Container:"
	@echo "  up            Start Docker container"
	@echo "  down          Stop Docker container"
	@echo "  build         Rebuild Docker image (no cache)"
	@echo "  shell         Open shell in container"
	@echo "Dependencies:"
	@echo "  install       Install Composer dependencies"
	@echo "Assets:"
	@echo "  assets        No-op (no frontend assets in this bundle)"
	@echo "Tests:"
	@echo "  test          Run PHPUnit tests (starts container if needed)"
	@echo "  test-coverage Run tests with code coverage (starts container if needed)"
	@echo "Quality:"
	@echo "  cs-check      Check code style"
	@echo "  cs-fix        Fix code style"
	@echo "  rector        Apply Rector refactoring"
	@echo "  rector-dry    Run Rector in dry-run mode"
	@echo "  phpstan       Run PHPStan static analysis"
	@echo "  igor          Run Igor worker-state audit (REQ-CS-008)"
	@echo "  qa            Run all QA checks (cs-check + test)"
	@echo "Release:"
	@echo "  release-check Pre-release: ensure-up, co-author/open-PR/raw-form checks, composer-sync, cs-fix, cs-check, rector-dry, phpstan, validate-phpdoc, igor, validate-translations, test-coverage + 100% gate, demos"
	@echo "  composer-sync Validate composer.json and align composer.lock (no install)"
	@echo "Cleanup:"
	@echo "  clean         Remove vendor and cache"
	@echo "Composer:"
	@echo "  update        composer update in the bundle container only"
	@echo "  update-deps   composer update in the bundle, then in every demo (REQ-MAKE-008)"
	@echo "  validate      Run composer validate --strict"
	@echo ""
	@echo "Bundle-specific:"
	@echo "  down-dev               Stop containers (keep volumes; REQ-MAKE-007)"
	@echo "  test-coverage-100      Run tests with coverage and fail below 100% (REQ-TEST-003 / REQ-TEST-006)"
	@echo "  coverage-check         Fail when the last coverage.xml is below 100% statements/elements"
	@echo "  test-with-db           Database suite on the MySQL 8.4 service of docker-compose.yml (REQ-TEST-005)"
	@echo "  test-coverage-with-db  Database suite on MySQL with text coverage (REQ-TEST-005)"
	@echo "  validate-phpdoc        Fail when a class or public method in src/ has no PHPDoc (REQ-CS-001)"
	@echo "  validate-translations  YAML syntax + key parity of the 7 locales (REQ-MAKE-004 / REQ-I18N-002)"
	@echo "  check-no-raw-html-form No raw form tags in bundle or demo Twig (REQ-TWIG-005)"
	@echo "  check-twig-extra       twig/extra-bundle in the package and every Twig demo (REQ-TWIG-004)"
	@echo "  check-open-prs         Fail if unresolved open GitHub PRs remain (REQ-REL-003)"
	@echo "  setup-hooks            Install git hooks (REQ-GIT-001)"
	@echo ""
	@echo "Demos:"
	@echo "  demo-smoke    REQ-TEST-011: boot demo + HTTP 200 (make -C demo demo-smoke)"
	@echo "  test-e2e      REQ-DEMO-013: Playwright e2e against demo/symfony8 (start it first: make -C demo up-symfony8)"
	@echo "  demo-screenshots  Refresh docs/images/demo/*.webp from the running demo/symfony8"
	@echo "  make -C demo  up-symfony8, down-symfony8, demo-smoke, release-check, update-deps-all"
	@echo ""

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@echo "Installing dependencies..."
	$(COMPOSE) exec $(SERVICE_PHP) composer install --no-interaction
	@echo "✅ Container ready!"

down:
	$(COMPOSE) --profile db down

# Stop containers without removing volumes (REQ-MAKE-007)
down-dev:
	$(COMPOSE) down --remove-orphans

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install
	@echo "✅ Dependencies installed."

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container (root $(COMPOSE))..."; \
		$(COMPOSE) up -d; \
		sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

test: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test

test-coverage: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	sh .scripts/php-coverage-percent.sh coverage-php.txt

# REQ-TEST-003 / REQ-TEST-006: 100% statements and elements of src/ (no exclusions), non-zero exit below.
coverage-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/check-coverage.php coverage.xml --min-percent=100

test-coverage-100: test-coverage coverage-check

# REQ-CS-001: every class and public method of src/ has a PHPDoc block.
validate-phpdoc: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate-phpdoc

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

qa: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer qa

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict


# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer igor
# REQ-MAKE-002: sequential chain, ensure-up first.
release-check:
	@$(MAKE) ensure-up
	@$(MAKE) check-no-cursor-coauthor
	@$(MAKE) check-open-prs
	@$(MAKE) check-no-raw-html-form
	@$(MAKE) check-twig-extra
	@$(MAKE) composer-sync
	@$(MAKE) cs-fix
	@$(MAKE) cs-check
	@$(MAKE) rector-dry
	@$(MAKE) phpstan
	@$(MAKE) validate-phpdoc
	@$(MAKE) igor
	@$(MAKE) validate-translations
	@$(MAKE) test-coverage
	@$(MAKE) coverage-check
	@$(MAKE) release-check-demos

# REQ-TWIG-004: twig/extra-bundle in the package and in every demo that renders Twig.
check-twig-extra:
	@chmod +x .scripts/check-twig-extra.sh
	@./.scripts/check-twig-extra.sh

# REQ-MAKE-004 / REQ-I18N-002: YAML syntax + key parity (en, es, it, fr, pt, de, nl).
validate-translations: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/validate-translations.php

# REQ-TEST-005: the Database suite (tests/Integration/Database, standard + DDD hosts) against the MySQL 8.4 service
# of the root docker-compose.yml (profile "db"); `make test` runs the same suite on SQLite; PostgreSQL 17 runs in CI.
TEST_DB_URL := mysql://root@database:3306/app?serverVersion=8.4.0&charset=utf8mb4

db-up: ensure-up
	$(COMPOSE) --profile db up -d database
	@i=0; until $(COMPOSE) --profile db exec -T database mysqladmin ping -h 127.0.0.1 -uroot --silent >/dev/null 2>&1; do \
		i=$$((i+1)); if [ $$i -gt 60 ]; then echo "MySQL not ready"; exit 1; fi; sleep 2; done

test-with-db: db-up
	$(COMPOSE) exec -T -e NOWO_TEST_DATABASE_URL='$(TEST_DB_URL)' $(SERVICE_PHP) vendor/bin/phpunit --testsuite Database

test-coverage-with-db: db-up
	$(COMPOSE) exec -T -e NOWO_TEST_DATABASE_URL='$(TEST_DB_URL)' $(SERVICE_PHP) vendor/bin/phpunit --testsuite Database --coverage-text

# REQ-TWIG-005: no raw <form>/<input>/<textarea>/<select> in bundle or demo Twig templates.
check-no-raw-html-form:
	@chmod +x .scripts/check-no-raw-html-form.sh
	@./.scripts/check-no-raw-html-form.sh

release-check-demos:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo release-check; else echo "No demo/Makefile — skip release-check-demos"; fi

# REQ-DEMO-013: Playwright e2e + README screenshots against the running demo/symfony8.
test-e2e:
	$(MAKE) -C demo/symfony8 test-e2e

demo-screenshots:
	$(MAKE) -C demo/symfony8 demo-screenshots

# REQ-TEST-011: demo smoke (HTTP 200); also part of demo release-check
demo-smoke:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo demo-smoke; else echo "No demo/Makefile — skip demo-smoke"; fi

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction --prefer-dist

clean: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c "rm -rf vendor .phpunit.cache coverage coverage.xml .php-cs-fixer.cache"

assets:
	@echo "No frontend assets in this bundle (pages use UiKit assets)."



setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

# REQ-MAKE-008: update-deps (optional include: monorepo path exists locally; absent on GitHub Actions checkout)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

# REQ-REL-003 / REQ-MAKE-002
check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@bash .scripts/check-open-prs.sh

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main
