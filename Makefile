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
.PHONY        : help build up start down logs sh install composer vendor sf cc test release

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

## —— Quality ✅ ——————————————————————————————————————————————
test: ## Run the test suite, pass PHPUnit options with ARGS, e.g. make test ARGS="--filter=Kernel"
	@$(PHP) bin/phpunit $(ARGS)

## —— Release 📦 ——————————————————————————————————————————————
release: ## Cut a release with release-it (run locally: main is protected, the CI bot cannot push to it)
	@npm run release
