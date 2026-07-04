.PHONY: help install install-deps alias init doctor doctor-smoke clean-logs test test-integration lint lint-fix check check-entrypoint-local ci-current chrome-ext-paths chrome-ext-install chrome-ext-uninstall

PHP ?= php
COMPOSER ?= composer
PHP_ALIAS_NAME ?= ytdphp
CHROME_EXT_DIR ?= chrome-ext
CHROME_EXT_EXTENSION_DIR ?= $(CHROME_EXT_DIR)/extension
CHROME_EXT_NATIVE_HOST_DIR ?= $(CHROME_EXT_DIR)/native-host
CHROME_EXT_INSTALLER ?= $(CHROME_EXT_NATIVE_HOST_DIR)/install-macos.sh
CHROME_EXT_UNINSTALLER ?= $(CHROME_EXT_NATIVE_HOST_DIR)/uninstall-macos.sh
CHROME_EXT_ID ?=

help:
	@echo "Available targets:"
	@echo ""
	@echo "Install:"
	@echo "  make install       - add $(PHP_ALIAS_NAME) alias to ~/.zshrc"
	@echo "  make install-deps  - install Composer dependencies"
	@echo ""
	@echo "Project commands:"
	@echo "  make init          - create runtime config files from templates"
	@echo "  make clean-logs    - remove all log files"
	@echo "  make doctor        - run environment checks"
	@echo "  make doctor-smoke  - run doctor against template configs in a temporary runtime root"
	@echo "  make chrome-ext-paths - print Chrome extension and native host paths"
	@echo "  make chrome-ext-install - install Chrome native host for the local extension"
	@echo "  make chrome-ext-uninstall - uninstall Chrome native host manifest"
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

clean-logs:
	rm -rf logs/* *.log
	@echo "Logs cleared."

doctor:
	$(PHP) bin/ytd --doctor

doctor-smoke:
	@tmpdir="$$(mktemp -d /tmp/ytd-php-doctor.XXXXXX)"; \
	cp .env.example "$$tmpdir/.env"; \
	cp proxy_rules.example.yaml "$$tmpdir/proxy_rules.yaml"; \
	YTD_PROJECT_ROOT="$$tmpdir" YTD_DOCTOR_SKIP_BINARY_CHECKS=1 $(PHP) bin/ytd --doctor; \
	rc="$$?"; \
	rm -rf "$$tmpdir"; \
	exit "$$rc"

chrome-ext-paths:
	@echo "Chrome extension directory:"
	@echo "  $(PWD)/$(CHROME_EXT_EXTENSION_DIR)"
	@echo "Native host directory:"
	@echo "  $(PWD)/$(CHROME_EXT_NATIVE_HOST_DIR)"

chrome-ext-install:
	@if [ ! -x "$(CHROME_EXT_INSTALLER)" ]; then \
		echo "Installer not found: $(CHROME_EXT_INSTALLER)" >&2; \
		exit 1; \
	fi
	@if [ -n "$(CHROME_EXT_ID)" ]; then \
		"$(CHROME_EXT_INSTALLER)" --extension-id="$(CHROME_EXT_ID)"; \
	else \
		"$(CHROME_EXT_INSTALLER)"; \
	fi

chrome-ext-uninstall:
	@if [ ! -x "$(CHROME_EXT_UNINSTALLER)" ]; then \
		echo "Uninstaller not found: $(CHROME_EXT_UNINSTALLER)" >&2; \
		exit 1; \
	fi
	@"$(CHROME_EXT_UNINSTALLER)"

test:
	$(COMPOSER) test

test-integration:
	$(COMPOSER) test-integration

lint:
	$(COMPOSER) lint

lint-fix:
	$(COMPOSER) lint-fix

check:
	$(COMPOSER) check

check-entrypoint-local:
	$(PHP) -r '[$$a,$$b]=[(string)shell_exec("php bin/ytd --help"),(string)shell_exec("php ytd.php --help")]; if ($$a !== $$b) { fwrite(STDERR, "bin/ytd help does not match ytd.php help\n"); exit(1);} '

ci-current:
	@echo "Running local CI checks for $$($(PHP) -v | head -n 1)..."
	$(MAKE) lint
	$(MAKE) test
	$(MAKE) check-entrypoint-local
	$(MAKE) doctor-smoke
