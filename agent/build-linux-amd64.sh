#!/usr/bin/env bash
# Build PortShield agent for Linux amd64.
# Run from a machine that has Go installed (laptop, CI, or install Go on server).
#
# Usage:
#   ./build-linux-amd64.sh                    # build in agent/
#   ./build-linux-amd64.sh /path/to/backend   # build and copy to backend/storage/app/agents/linux-amd64

set -e
cd "$(dirname "$0")"

if ! command -v go >/dev/null 2>&1; then
  echo "Go is not installed. Install with: apt install golang-go   OR   build on another machine and scp the binary."
  exit 1
fi

GOOS=linux GOARCH=amd64 go build -o portshield-agent .
echo "Built: $(pwd)/portshield-agent"

DEST="${1:-}"
if [ -n "$DEST" ]; then
  AGENTS_DIR="$DEST/storage/app/agents"
  if [ ! -d "$AGENTS_DIR" ]; then
    mkdir -p "$AGENTS_DIR"
  fi
  cp portshield-agent "$AGENTS_DIR/linux-amd64"
  chmod 644 "$AGENTS_DIR/linux-amd64"
  echo "Copied to $AGENTS_DIR/linux-amd64"
fi
