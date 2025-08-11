SHELL := /bin/sh
DC := docker compose
APP := app
WEB := web
NODE := node
DB := db

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*?## "}; /^[a-zA-Z0-9_%-]+:.*?## / { printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

.PHONY: init
init: ## Build containers, start stack, scaffold Laravel app, run migrations/seeders (idempotent)
	@[ -f .env ] || cp .env.example .env
	$(DC) build
	$(DC) up -d
	@echo "Waiting for Laravel bootstrap to complete..."
	@set -e; \
	i=0; \
	while [ $$i -lt 180 ]; do \
		if $(DC) exec -T $(APP) sh -lc "test -f /var/www/html/laravel/.bootstrap_done" >/dev/null 2>&1; then \
			echo "Laravel bootstrap completed."; \
			break; \
		fi; \
		i=$$((i+1)); \
		sleep 2; \
	done; \
	if [ $$i -ge 180 ]; then \
		echo "Timeout waiting for Laravel bootstrap."; \
		exit 1; \
	fi
	@echo "Vite dev server should be running in the node container on http://localhost:5173"
	@echo "App available at http://localhost:8080 (Filament at /admin)"

.PHONY: up
up: ## Start the stack in background
	$(DC) up -d

.PHONY: down
down: ## Stop and remove the stack
	$(DC) down

.PHONY: restart
restart: ## Restart the stack
	$(DC) down
	$(DC) up -d

.PHONY: logs
logs: ## Tail logs for all services
	$(DC) logs -f

.PHONY: migrate
migrate: ## Run database migrations
	$(DC) exec $(APP) php /var/www/html/laravel/artisan migrate

.PHONY: seed
seed: ## Run database seeders
	$(DC) exec $(APP) php /var/www/html/laravel/artisan db:seed

.PHONY: tinker
tinker: ## Open Laravel tinker
	$(DC) exec $(APP) php /var/www/html/laravel/artisan tinker

.PHONY: test
test: ## Run PHPUnit tests
	$(DC) exec -T $(APP) php /var/www/html/laravel/artisan test -q

.PHONY: lint
lint: ## Run Pint if available (non-fatal if missing)
	-$(DC) exec -T $(APP) sh -lc '[ -f vendor/bin/pint ] && vendor/bin/pint -v -- --test || echo "Pint not installed (skipping)"'

.PHONY: cache-clear
cache-clear: ## Clear caches
	$(DC) exec $(APP) php /var/www/html/laravel/artisan optimize:clear

.PHONY: xdebug-on
xdebug-on: ## Enable Xdebug via .env and restart (Linux/mac sed)
	@sed -i 's/^XDEBUG=.*/XDEBUG=1/' .env || true
	@sed -i 's/^XDEBUG_MODE=.*/XDEBUG_MODE=debug,develop/' .env || true
	$(MAKE) restart

.PHONY: xdebug-off
xdebug-off: ## Disable Xdebug via .env and restart (Linux/mac sed)
	@sed -i 's/^XDEBUG=.*/XDEBUG=0/' .env || true
	@sed -i 's/^XDEBUG_MODE=.*/XDEBUG_MODE=off/' .env || true
	$(MAKE) restart