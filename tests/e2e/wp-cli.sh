#!/usr/bin/env bash
set -euo pipefail
exec docker compose -f tests/e2e/docker-compose.yml run --rm cli "$@"
