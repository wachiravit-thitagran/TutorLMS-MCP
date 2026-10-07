#!/usr/bin/env bash
set -euo pipefail

WORDPRESS_CHANNEL="${WORDPRESS_CHANNEL:-stable}"
TUTOR_VERSION="${TUTOR_VERSION:-4.1.1}"
MCP_ADAPTER_URL="${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip}"
ACTIVATE_PLUGIN_SLUG="${ACTIVATE_PLUGIN_SLUG:-TutorLMS-MCP}"
BASE_URL="${WP_BASE_URL:-http://localhost:8888}"

docker compose -f tests/e2e/docker-compose.yml up -d db wordpress

for i in $(seq 1 60); do
  if curl -4 --silent --output /dev/null --write-out '%{http_code}' "$BASE_URL" | grep -Eq '200|302|500'; then
    break
  fi
  if [ "$i" -eq 60 ]; then
    echo "::error::WordPress container did not become reachable."
    docker compose -f tests/e2e/docker-compose.yml logs wordpress db || true
    exit 1
  fi
  sleep 2
done

if ! bash tests/e2e/wp-cli.sh core is-installed >/dev/null 2>&1; then
  bash tests/e2e/wp-cli.sh core install     --url="$BASE_URL"     --title="TutorLMS MCP Test"     --admin_user=admin     --admin_password=password     --admin_email=admin@example.test     --skip-email
fi

if [ "$WORDPRESS_CHANNEL" = "nightly" ]; then
  bash tests/e2e/wp-cli.sh core update https://wordpress.org/nightly-builds/wordpress-latest.zip --force
  bash tests/e2e/wp-cli.sh core update-db
fi

if [ -n "$TUTOR_VERSION" ]; then
  bash tests/e2e/wp-cli.sh plugin install tutor --version="$TUTOR_VERSION" --activate
else
  bash tests/e2e/wp-cli.sh plugin install tutor --activate
fi

bash tests/e2e/wp-cli.sh plugin install "$MCP_ADAPTER_URL" --activate

if [ -n "$ACTIVATE_PLUGIN_SLUG" ]; then
  bash tests/e2e/wp-cli.sh plugin activate "$ACTIVATE_PLUGIN_SLUG"
fi

bash tests/e2e/wp-cli.sh plugin list
