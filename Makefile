# Convenience targets for IP Tools. Run `make` (or `make help`) to list them.

IMAGE      := ip-finder
GIT_COMMIT := $(shell git rev-parse HEAD 2>/dev/null)
export GIT_COMMIT

.DEFAULT_GOAL := help
.PHONY: help dev down logs build test unit tor clean

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-7s\033[0m %s\n", $$1, $$2}'

dev: ## Build + run locally at http://localhost:8090 (bakes the git SHA as the version)
	docker compose up -d --build

down: ## Stop the local container
	docker compose down

logs: ## Tail local container logs
	docker compose logs -f

build: ## Build the image with the current git SHA baked in
	docker build --build-arg GIT_COMMIT="$(GIT_COMMIT)" -t $(IMAGE) .

test: ## Full suite: unit tests + integration (build, boot, curl the endpoints)
	bash tests/integration.sh

unit: ## Unit tests only (runs inside the php:8.3 image, no host PHP needed)
	docker run --rm --entrypoint php -v "$(CURDIR)":/app -w /app php:8.3-cli tests/unit.php

tor: ## Run the Tor connection checker inside the running container
	docker exec -it ip-tools python /usr/local/bin/tor_check.py

clean: ## Stop the local container and remove built images
	-docker compose down
	-docker rmi $(IMAGE) ip-finder-ci 2>/dev/null || true
