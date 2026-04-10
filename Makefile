.PHONY: help install install-deps alias init doctor doctor-smoke test test-integration lint check

PHP ?= php
COMPOSER ?= composer
PHP_ALIAS_NAME ?= ytdphp

help:
	@echo "Available targets:"
	@echo ""
	@echo "Install:"
	@echo "  make install       - add $(PHP_ALIAS_NAME) alias to ~/.zshrc"
	@echo "  make install-deps  - install Composer dependencies"
	@echo ""
	@echo "Project commands:"
	@echo "  make init          - create runtime config files from templates"
	@echo "  make doctor        - run environment checks"
	@echo "  make doctor-smoke  - run doctor against template configs in a temporary runtime root"
	@echo ""
	@echo "Checks:"
	@echo "  make test          - run unit tests"
	@echo "  make test-integration - run manual integration tests"
	@echo "  make lint          - run PHP CS Fixer and PHPStan"
	@echo "  make check         - run lint and unit tests"

install: alias

install-deps:
	$(COMPOSER) install

alias:
	@echo "Configuring alias in ~/.zshrc..."
	@if grep -q "alias $(PHP_ALIAS_NAME)=" ~/.zshrc; then \
		echo "Alias '$(PHP_ALIAS_NAME)' already exists in ~/.zshrc"; \
	else \
		echo 'alias $(PHP_ALIAS_NAME)="php $(PWD)/bin/ytd"' >> ~/.zshrc; \
		echo "Alias '$(PHP_ALIAS_NAME)' added. Please run 'source ~/.zshrc' to apply."; \
	fi

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
