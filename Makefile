.PHONY: help install install-deps alias init doctor doctor-smoke test test-integration lint lint-fix check check-entrypoint-local ci-current

PHP ?= php
COMPOSER ?= composer
PHP_ALIAS_NAME ?= ytdphp
FIXER ?= vendor/bin/php-cs-fixer
PHPSTAN ?= vendor/bin/phpstan
PHPUNIT ?= vendor/bin/phpunit

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
	@echo "  make lint-fix      - auto-fix PHP code style and then re-run lint"
	@echo "  make check         - run lint and unit tests"
	@echo "  make check-entrypoint-local - verify bin/ytd and ytd.php help output parity"
	@echo "  make ci-current    - run the local CI equivalent for the current PHP version"

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
	$(PHPUNIT) --testsuite unit

test-integration:
	$(PHPUNIT) --testsuite integration

lint:
	$(FIXER) fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.dist.php --sequential
	$(PHPSTAN) analyse --configuration=phpstan.neon --no-progress --debug

lint-fix:
	$(FIXER) fix --using-cache=no --config=.php-cs-fixer.dist.php --sequential $(FIXER_TARGETS)
	$(MAKE) lint

check:
	$(MAKE) lint
	$(MAKE) test

check-entrypoint-local:
	$(PHP) -r '[$$a,$$b]=[(string)shell_exec("php bin/ytd --help"),(string)shell_exec("php ytd.php --help")]; if ($$a !== $$b) { fwrite(STDERR, "bin/ytd help does not match ytd.php help\n"); exit(1);} '

ci-current:
	@echo "Running local CI checks for $$($(PHP) -v | head -n 1)..."
	$(MAKE) lint
	$(MAKE) test
	$(MAKE) check-entrypoint-local
	$(MAKE) doctor-smoke
