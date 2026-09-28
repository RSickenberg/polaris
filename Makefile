# Executables (local)
DOCKER_COMP = docker compose

# Docker containers
PHP_CONT = $(DOCKER_COMP) exec php

# Executables
PHP      = $(PHP_CONT) php
COMPOSER = $(PHP_CONT) composer
SYMFONY  = $(PHP) bin/console

# Misc
.DEFAULT_GOAL = help
ARGS          =
.PHONY        : help build up start down logs sh install composer vendor sf cc migrate diff test-db test phpstan cs cs-fix deptrac ci release

## —— 🧭 The Polaris Makefile 🧭 ——————————————————————————————————
help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— Docker 🐳 ————————————————————————————————————————————————
build: ## Build the Docker images (pull base images, no cache)
	@$(DOCKER_COMP) build --pull --no-cache

up: ## Start the containers in detached mode and wait until they are healthy
	@$(DOCKER_COMP) up --detach --wait

start: build up ## Build the images and start the containers

down: ## Stop the containers and remove orphans
	@$(DOCKER_COMP) down --remove-orphans

logs: ## Follow the container logs
	@$(DOCKER_COMP) logs --tail=0 --follow

sh: ## Open a shell in the php container
	@$(PHP_CONT) sh

## —— Setup 🧙 ——————————————————————————————————————————————————
install: vendor ## Install Composer deps (in the container) and release tooling (npm)
	@npm install

## —— Composer 🧙 ——————————————————————————————————————————————
composer: ## Run Composer, pass the command with ARGS, e.g. make composer ARGS="outdated"
	@$(COMPOSER) $(ARGS)

vendor: ## Install the Composer dependencies from composer.lock
	@$(COMPOSER) install --prefer-dist --no-progress --no-interaction

## —— Symfony 🎵 ———————————————————————————————————————————————
sf: ## Run bin/console, pass the command with ARGS, e.g. make sf ARGS="debug:router"
	@$(SYMFONY) $(ARGS)

cc: ## Clear the Symfony cache
	@$(SYMFONY) cache:clear

## —— Database 🐘 ——————————————————————————————————————————————
migrate: ## Apply the pending Doctrine migrations
	@$(SYMFONY) doctrine:migrations:migrate --no-interaction --all-or-nothing

diff: ## Generate a migration from the difference between the entity mapping and the database
	@$(SYMFONY) doctrine:migrations:diff --no-interaction

test-db: ## Create the test database if needed and apply the migrations to it
	@$(SYMFONY) doctrine:database:create --env=test --if-not-exists
	@$(SYMFONY) doctrine:migrations:migrate --env=test --no-interaction --all-or-nothing

## —— Quality ✅ ——————————————————————————————————————————————
test: test-db ## Run the test suite, pass PHPUnit options with ARGS, e.g. make test ARGS="--filter=Kernel"
	@$(PHP) bin/phpunit $(ARGS)

phpstan: ## Run PHPStan (level max) after warming up the dev container, as CI does
	@$(COMPOSER) phpstan

cs: ## Check the coding standard (PHP-CS-Fixer dry run), as CI does
	@$(COMPOSER) cs

cs-fix: ## Fix the coding standard (PHP-CS-Fixer)
	@$(COMPOSER) cs:fix

deptrac: ## Check the module boundaries of ADR 0001 (Deptrac), as CI does
	@$(COMPOSER) deptrac

ci: test-db ## Run every check CI runs: coding standard, PHPStan, Deptrac, tests
	@$(COMPOSER) ci

## —— Release 📦 ——————————————————————————————————————————————
release: ## Cut a release with release-it (run locally: main is protected, the CI bot cannot push to it)
	@npm run release
