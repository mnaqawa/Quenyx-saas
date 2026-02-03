#!/bin/bash
# Example observe plugin (shell). Copy to storage/app/observe_plugins/ (or OBSERVE_PLUGINS_DIR).
# Env: OBSERVE_HOST_ADDRESS, OBSERVE_CHECK_ARGS (JSON). Exit: 0=OK, 1=Warning, 2=Critical, 3=Unknown.
set -e
HOST="${OBSERVE_HOST_ADDRESS:-127.0.0.1}"
# Parse port from JSON (requires php or jq); fallback 8080
PORT=8080
if command -v php >/dev/null 2>&1; then
  PORT=$(echo "$OBSERVE_CHECK_ARGS" | php -r 'echo json_decode(file_get_contents("php://stdin"))->port ?? 8080;' 2>/dev/null || echo 8080)
fi
URL="http://${HOST}:${PORT}/health"
if curl -sf --connect-timeout 5 "$URL" >/dev/null; then
  echo "OK - health endpoint responded at $URL"
  exit 0
else
  echo "CRITICAL - health endpoint failed at $URL"
  exit 2
fi
