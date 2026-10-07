#!/usr/bin/env bash
set -euo pipefail

# The WordPress image initializes the shared named volume as the web container.
# Run CI-only WP-CLI maintenance as root so core/plugin installation can update
# wp-content, while WordPress itself continues serving as www-data.
exec docker compose -f tests/e2e/docker-compose.yml run --rm --user 0:0 cli wp --allow-root "$@"
