# Makefile for Short Link Bundle demos (Symfony 7.4 and 8.1, FrankenPHP worker mode, MySQL, UiKit + FormKit)
DEMOS := symfony7 symfony8

.PHONY: help up down update-bundle demo-smoke release-check release-verify clean

help:
	@echo "Short Link Bundle - Demos"
	@echo ""
	@echo "  make up-symfony7 / down-symfony7   Symfony 7.4 demo (http://localhost:8230)"
	@echo "  make up-symfony8 / down-symfony8   Symfony 8.1 demo (http://localhost:8231)"
	@echo "  make up / down                     Symfony 8.1 demo (primary)"
	@echo "  make build-<demo>                  Rebuild a demo image"
	@echo "  make install-<demo>                Install deps"
	@echo "  make setup-<demo>                  install + database"
	@echo "  make shell-<demo>                  Shell in the PHP container"
	@echo "  make update-bundle-<demo>          Sync the path-repo bundle into a demo"
	@echo "  make demo-smoke                    REQ-TEST-011: boot every demo + HTTP 200"
	@echo "  make release-check                 Pre-release demo verification (every demo)"
	@echo ""

up: up-symfony8

down: down-symfony8

update-bundle: update-bundle-symfony8

up-%:
	$(MAKE) -C $* up

down-%:
	$(MAKE) -C $* down

build-%:
	$(MAKE) -C $* build

install-%:
	$(MAKE) -C $* install

setup-%:
	$(MAKE) -C $* setup

shell-%:
	$(MAKE) -C $* shell

update-bundle-%:
	$(MAKE) -C $* update-bundle

verify-%:
	$(MAKE) -C $* up

# REQ-TEST-011: boot each demo in turn + one HTTP 200 on / (one stack at a time: shared machine).
demo-smoke:
	@for demo in $(DEMOS); do \
		PORT=$$(grep "^PORT=" $$demo/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" $$demo/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		echo "Demo smoke: $$demo (http://127.0.0.1:$$PORT)"; \
		$(MAKE) -C $$demo up || { $(MAKE) -C $$demo down 2>/dev/null; exit 1; }; \
		code=$$(curl -sL -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/" || true); \
		[ -z "$$code" ] && code=000; \
		$(MAKE) -C $$demo down 2>/dev/null || true; \
		if [ "$$code" != "200" ]; then echo "$$demo failed with HTTP $$code"; exit 1; fi; \
		echo "$$demo OK (HTTP $$code)"; \
	done

release-verify:
	@for demo in $(DEMOS); do \
		port=$$(grep '^PORT=' $$demo/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		[ -z "$$port" ] && port=$$(grep '^PORT=' $$demo/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		echo "Release verify: $$demo (port $$port)..."; \
		$(MAKE) -C $$demo up || exit 1; \
		$(MAKE) -C $$demo update-bundle && $(MAKE) -C $$demo test || { $(MAKE) -C $$demo down 2>/dev/null; exit 1; }; \
		code=$$(curl -sL -o /dev/null -w "%{http_code}" http://127.0.0.1:$$port/ 2>/dev/null || echo "000"); \
		$(MAKE) -C $$demo down 2>/dev/null || true; \
		if [ "$$code" != "200" ]; then echo "Demo $$demo healthcheck failed (HTTP $$code)"; exit 1; fi; \
		echo "OK $$demo (HTTP $$code)"; \
	done
	@echo "All demos passed release-verify."

# REQ-DEMO-007: release-verify syncs the bundle (update-bundle) and runs the demo checks before the HTTP check
release-check: release-verify
	@echo "All demos passed release-check."

clean:
	@for demo in $(DEMOS); do rm -rf ./$$demo/vendor ./$$demo/var ./$$demo/.phpunit.cache; done
	@echo "Cleaned demos."

# REQ-MAKE-008: update-deps-all
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
