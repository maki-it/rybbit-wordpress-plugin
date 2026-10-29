# Development tasks for Integrate Rybbit.
# All PHP and WordPress tools run in Docker. Only Docker, make and zip are needed on the host.

PLUGIN        := integrate-rybbit
PHP_VERSIONS  ?= 7.4 8.0 8.1 8.2 8.3 8.4 8.5
TOOLS_IMAGE   ?= integrate-rybbit-tools
PLAYWRIGHT_VERSION ?= 1.64.1
WP_PORT       ?= 8080
WP_URL        ?= http://localhost:$(WP_PORT)
DOCKER_USER   := $(shell id -u):$(shell id -g)

COMPOSE := WP_PORT=$(WP_PORT) docker compose --progress quiet -f tools/compose.yaml
# -T turns off the TTY. With a TTY, "2>/dev/null" does not hide the errors of WP-CLI.
WPCLI   := $(COMPOSE) run --rm --no-deps -T wpcli

.DEFAULT_GOAL := help

.PHONY: help check lint tools phpcs phpcbf dev plugin-check test-dashboard test-optout pot translations screenshots logs down clean zip

help: ## Show the available targets.
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z_-]+:.*## / {printf "  %-14s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

check: lint phpcs plugin-check ## Run all checks (lint, phpcs, plugin-check).

lint: ## Check the PHP syntax with every supported PHP version.
	@set -e; for v in $(PHP_VERSIONS); do \
		echo "==> php -l (PHP $$v)"; \
		docker run --rm -v "$(CURDIR)/$(PLUGIN)":/app:ro -w /app php:$$v-cli-alpine \
			sh -c 'rc=0; for f in $$(find . -name "*.php"); do out=$$(php -l "$$f" 2>&1) || { echo "$$out"; rc=1; }; done; exit $$rc'; \
	done

tools: ## Build the Docker image with PHPCS, WPCS and PHPCompatibilityWP.
	@docker build -q -t $(TOOLS_IMAGE) tools > /dev/null

phpcs: tools ## Check the coding standards (phpcs.xml.dist).
	docker run --rm -u "$(DOCKER_USER)" -v "$(CURDIR)":/app -w /app $(TOOLS_IMAGE) phpcs

phpcbf: tools ## Fix coding standard issues that can be fixed automatically.
	docker run --rm -u "$(DOCKER_USER)" -v "$(CURDIR)":/app -w /app $(TOOLS_IMAGE) phpcbf || [ $$? -eq 1 ]

dev: ## Start WordPress (port 8080) with the plugin active (admin / admin).
	@$(COMPOSE) up -d --wait
	@$(WPCLI) wp core is-installed 2>/dev/null || $(WPCLI) wp core install \
		--url="$(WP_URL)" --title="Rybbit Dev" \
		--admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
	@$(WPCLI) wp plugin activate $(PLUGIN) --quiet
	@# Settings for the mock Rybbit API. "option add" does nothing if the option already exists.
	@$(WPCLI) wp option add rybbit_site_id demo-site 2>/dev/null || true
	@$(WPCLI) wp option add rybbit_api_url http://mock-rybbit:3000 2>/dev/null || true
	@$(WPCLI) wp option add rybbit_api_key demo-key 2>/dev/null || true
	@echo "WordPress: $(WP_URL)/wp-admin/options-general.php?page=$(PLUGIN) (admin / admin)"
	@echo "Analytics: $(WP_URL)/wp-admin/index.php?page=integrate-rybbit-dashboard (mock API)"

# wp plugin check always exits with 0. The target fails if the output has ERROR or WARNING rows.
plugin-check: dev ## Run the official Plugin Check against the plugin.
	@$(WPCLI) wp plugin is-installed plugin-check || $(WPCLI) wp plugin install plugin-check
	@$(WPCLI) wp plugin activate plugin-check --quiet
	@out="$$($(WPCLI) wp plugin check $(PLUGIN) --format=table 2>&1)"; \
		printf '%s\n' "$$out"; \
		if printf '%s\n' "$$out" | awk -F '\t' '$$3 == "ERROR" || $$3 == "WARNING" { found = 1 } END { exit !found }'; then \
			echo "Plugin Check found problems."; exit 1; \
		fi

# The test resets the Rybbit settings of the dev site to the mock API values.
test-dashboard: dev ## Test the dashboard backend against the mock Rybbit API.
	@$(WPCLI) wp eval-file /tests/dashboard.php

# The PHP test restores the Rybbit settings of the dev site. The browser test needs the page that optout-pages.php creates.
test-optout: dev ## Test the opt-out shortcode and block (PHP checks and browser test).
	@$(WPCLI) wp eval-file /tests/optout.php
	@$(WPCLI) wp eval-file /tests/optout-pages.php
	docker run --rm --ipc=host -u "$(DOCKER_USER)" -e HOME=/tmp -e WP_PORT=$(WP_PORT) \
		--network integrate-rybbit_default \
		-v "$(CURDIR)/tools/tests/optout.cjs":/optout.cjs:ro \
		mcr.microsoft.com/playwright:v$(PLAYWRIGHT_VERSION)-noble \
		sh -c 'mkdir -p /tmp/pw && cd /tmp/pw && npm init -y > /dev/null && npm i --silent playwright@$(PLAYWRIGHT_VERSION) && NODE_PATH=/tmp/pw/node_modules node /optout.cjs'

pot: ## Generate build/integrate-rybbit.pot to review the translatable strings.
	@mkdir -p build
	docker run --rm -u "$(DOCKER_USER)" -e HOME=/tmp -v "$(CURDIR)":/app -w /app wordpress:cli \
		wp i18n make-pot $(PLUGIN) build/$(PLUGIN).pot --slug=$(PLUGIN) --domain=$(PLUGIN)

# --no-purge keeps the strings of the JavaScript files in the .po files.
translations: ## Compile the .po files in integrate-rybbit/languages to .mo and JSON files.
	docker run --rm -u "$(DOCKER_USER)" -e HOME=/tmp -v "$(CURDIR)":/app -w /app wordpress:cli \
		sh -c 'wp i18n make-mo $(PLUGIN)/languages && wp i18n make-json $(PLUGIN)/languages --no-purge'

# The script fills the settings with example values and saves them. Use it only with the dev WordPress.
screenshots: dev ## Retake the screenshots in assets/ with a headless browser.
	@$(WPCLI) wp eval-file /tests/optout-pages.php > /dev/null
	docker run --rm --ipc=host -u "$(DOCKER_USER)" -e HOME=/tmp -e WP_PORT=$(WP_PORT) \
		--network integrate-rybbit_default \
		-v "$(CURDIR)/tools/screenshots.cjs":/screenshots.cjs:ro -v "$(CURDIR)/assets":/out \
		mcr.microsoft.com/playwright:v$(PLAYWRIGHT_VERSION)-noble \
		sh -c 'mkdir -p /tmp/pw && cd /tmp/pw && npm init -y > /dev/null && npm i --silent playwright@$(PLAYWRIGHT_VERSION) && NODE_PATH=/tmp/pw/node_modules node /screenshots.cjs'

logs: ## Show the WordPress debug log.
	$(COMPOSE) exec wordpress sh -c 'cat wp-content/debug.log 2>/dev/null || echo "No debug.log yet."'

down: ## Stop the development environment.
	$(COMPOSE) --profile cli down

clean: ## Stop the development environment and delete all data and build files.
	$(COMPOSE) --profile cli down -v
	-docker image rm $(TOOLS_IMAGE) 2>/dev/null
	rm -rf build $(PLUGIN).zip

zip: ## Build integrate-rybbit.zip for a manual upload.
	rm -f $(PLUGIN).zip
	cp LICENSE $(PLUGIN)/LICENSE
	zip -rq $(PLUGIN).zip $(PLUGIN) -x '*.DS_Store'
	rm -f $(PLUGIN)/LICENSE
