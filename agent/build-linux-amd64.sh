#!/usr/bin/env bash
# Build Quenyx agent for Linux amd64.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"
GOOS=linux GOARCH=amd64 go build -o quenyx-agent .
echo "Built: $(pwd)/quenyx-agent"
# Backend serves binaries as storage/app/agents/{platform} (e.g. file named "linux-amd64")
AGENTS_DIR="${AGENTS_DIR:-../backend/storage/app/agents}"
if [ -d "$AGENTS_DIR" ]; then
  cp quenyx-agent "$AGENTS_DIR/linux-amd64"
  echo "Copied to $AGENTS_DIR/linux-amd64"
fi
