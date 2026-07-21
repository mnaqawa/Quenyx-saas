#!/usr/bin/env bash
# Print a single GATEWAY_INTERNAL_SECRET value (256-bit hex).
# Copy the output into backend/.env and gateway/.env (same value both sides).
set -euo pipefail
openssl rand -hex 32
