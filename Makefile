SHELL := /bin/bash

# Variables definitions
# -----------------------------------------------------------------------------

ifeq ($(TIMEOUT),)
TIMEOUT := 60
endif

ifeq ($(MODEL_PATH),)
MODEL_PATH := ./ml/model/
endif

ifeq ($(MODEL_NAME),)
MODEL_NAME := model.pkl
endif

# Target section and Global definitions
# -----------------------------------------------------------------------------

.PHONY: all clean test install run deploy down venv generate_dot_env

all: clean test install run deploy down

venv:
	@if [ ! -d ".venv" ]; then \
		uv venv .venv; \
	fi

test: install
	uv run pytest tests -vv --show-capture=all

install: generate_dot_env venv
	uv sync --dev

run: venv
	@if [ -n "$${SSL_CERTFILE:-}" ] && [ -n "$${SSL_KEYFILE:-}" ]; then \
		PYTHONPATH=app/ uv run uvicorn main:app --reload --host 0.0.0.0 --port 8080 --ssl-certfile "$$SSL_CERTFILE" --ssl-keyfile "$$SSL_KEYFILE"; \
	else \
		PYTHONPATH=app/ uv run uvicorn main:app --reload --host 0.0.0.0 --port 8080; \
	fi

deploy: generate_dot_env
	docker-compose build
	docker-compose up -d

down:
	docker-compose down

generate_dot_env:
	@if [ ! -e .env ]; then \
		touch .env; \
	fi

clean:
	@find . -name '*.pyc' -exec rm -rf {} \;
	@find . -name '__pycache__' -exec rm -rf {} \;
	@find . -name 'Thumbs.db' -exec rm -rf {} \;
	@find . -name '*~' -exec rm -rf {} \;
	rm -rf .cache
	rm -rf build
	rm -rf dist
	rm -rf *.egg-info
	rm -rf htmlcov
	rm -rf .tox/
	rm -rf docs/_build
