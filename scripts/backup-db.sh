#!/usr/bin/env bash
#
# Quenyx vOPS HUB — database backup (GA remediation).
#
# Creates a compressed, timestamped logical backup of the MySQL database using
# values from backend/.env (or environment overrides), verifies the dump is
# non-empty and gzip-valid, writes a SHA-256 checksum, and prunes backups older
# than the retention window. Safe to run from cron.
#
# Usage:
#   scripts/backup-db.sh [output_dir]
#
# Environment overrides (else read from backend/.env):
#   DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
#   BACKUP_DIR (default: /var/backups/quenyx)
#   BACKUP_RETENTION_DAYS (default: 14)
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${REPO_ROOT}/backend/.env"

# Load DB_* from backend/.env without leaking other variables into the shell.
load_env() {
  local key="$1"
  if [[ -f "${ENV_FILE}" ]]; then
    sed -n "s/^${key}=//p" "${ENV_FILE}" | head -n1 | sed 's/^"\(.*\)"$/\1/; s/^'\''\(.*\)'\''$/\1/'
  fi
}

DB_HOST="${DB_HOST:-$(load_env DB_HOST)}"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-$(load_env DB_PORT)}"; DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-$(load_env DB_DATABASE)}"
DB_USERNAME="${DB_USERNAME:-$(load_env DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(load_env DB_PASSWORD)}"

BACKUP_DIR="${1:-${BACKUP_DIR:-/var/backups/quenyx}}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"

if [[ -z "${DB_DATABASE}" || -z "${DB_USERNAME}" ]]; then
  echo "ERROR: DB_DATABASE / DB_USERNAME not resolved (set env or backend/.env)." >&2
  exit 1
fi

mkdir -p "${BACKUP_DIR}"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
OUT_FILE="${BACKUP_DIR}/quenyx-${DB_DATABASE}-${TIMESTAMP}.sql.gz"

echo "[backup] dumping ${DB_DATABASE} -> ${OUT_FILE}"
MYSQL_PWD="${DB_PASSWORD}" mysqldump \
  --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USERNAME}" \
  --single-transaction --quick --routines --triggers --events \
  --default-character-set=utf8mb4 \
  "${DB_DATABASE}" | gzip -c > "${OUT_FILE}"

# Verify: non-empty + valid gzip + contains a CREATE/INSERT statement.
if [[ ! -s "${OUT_FILE}" ]]; then
  echo "ERROR: backup file is empty." >&2
  exit 1
fi
if ! gzip -t "${OUT_FILE}"; then
  echo "ERROR: backup gzip integrity check failed." >&2
  exit 1
fi
if ! zcat "${OUT_FILE}" | head -n 50 | grep -qiE 'CREATE TABLE|INSERT INTO|MySQL dump'; then
  echo "ERROR: backup does not look like a valid MySQL dump." >&2
  exit 1
fi

sha256sum "${OUT_FILE}" > "${OUT_FILE}.sha256"
echo "[backup] checksum written: ${OUT_FILE}.sha256"

# Prune old backups.
find "${BACKUP_DIR}" -name 'quenyx-*.sql.gz' -type f -mtime "+${RETENTION_DAYS}" -print -delete || true
find "${BACKUP_DIR}" -name 'quenyx-*.sql.gz.sha256' -type f -mtime "+${RETENTION_DAYS}" -delete || true

echo "[backup] OK ($(du -h "${OUT_FILE}" | cut -f1))"
