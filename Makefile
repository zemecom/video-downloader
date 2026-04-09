.PHONY: install init doctor doctor-smoke test test-integration lint check

PHP ?= php
COMPOSER ?= composer

install:
	$(COMPOSER) install

init:
	@test -f .env || cp .env.example .env
	@test -f proxy_rules.yaml || cp proxy_rules.example.yaml proxy_rules.yaml
	@echo "Project initialized."

doctor:
	$(PHP) bin/ytd --doctor

doctor-smoke:
	@tmpdir="$$(mktemp -d /tmp/ytd-php-doctor.XXXXXX)"; \
	cp .env.example "$$tmpdir/.env"; \
	cp proxy_rules.example.yaml "$$tmpdir/proxy_rules.yaml"; \
	YTD_PROJECT_ROOT="$$tmpdir" $(PHP) bin/ytd --doctor; \
	rc="$$?"; \
	rm -rf "$$tmpdir"; \
	exit "$$rc"

test:
	$(COMPOSER) test

test-integration:
	$(COMPOSER) test-integration

lint:
	$(COMPOSER) lint

check:
	$(COMPOSER) check
