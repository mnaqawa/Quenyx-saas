#!/usr/bin/env bash
#
# Quenyx vOPS HUB — database restore + restore verification (GA remediation).
#
# Verifies a backup's checksum and gzip integrity, then restores it. By default
# it performs a SAFE verification restore into a temporary throwaway database
# (proves the backup is restorable WITHOUT touching the live database). Pass
# --target <db> to restore into a specific database (e.g. the live one).
#
# Usage:
#   scripts/restore-db.sh <backup_file.sql.gz>                 # verify-only (temp DB)
#   scripts/restore-db.sh <backup_file.sql.gz> --target <db>  # real restore
#
# Environment overrides (else read from backend/.env):
#   DB_HOST DB_PORT DB_USERNAME DB_PASSWORD
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${REPO_ROOT}/backend/.env"

load_env() {
  local key="$1"
  if [[ -f "${ENV_FILE}" ]]; then
    sed -n "s/^${key}=//p" "${ENV_FILE}" | head -n1 | sed 's/^"\(.*\)"$/\1/; s/^'\''\(.*\)'\''$/\1/'
  fi
}

BACKUP_FILE="${1:-}"
if [[ -z "${BACKUP_FILE}" || ! -f "${BACKUP_FILE}" ]]; then
  echo "ERROR: provide a valid backup file. Usage: restore-db.sh <file.sql.gz> [--target <db>]" >&2
  exit 1
fi

TARGET_DB=""
if [[ "${2:-}" == "--target" ]]; then
  TARGET_DB="${3:-}"
  if [[ -z "${TARGET_DB}" ]]; then
    echo "ERROR: --target requires a database name." >&2
    exit 1
  fi
fi

DB_HOST="${DB_HOST:-$(load_env DB_HOST)}"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-$(load_env DB_PORT)}"; DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:-$(load_env DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(load_env DB_PASSWORD)}"

# 1) Integrity checks before doing anything destructive.
echo "[restore] verifying gzip integrity..."
gzip -t "${BACKUP_FILE}"

if [[ -f "${BACKUP_FILE}.sha256" ]]; then
  echo "[restore] verifying checksum..."
  ( cd "$(dirname "${BACKUP_FILE}")" && sha256sum -c "$(basename "${BACKUP_FILE}").sha256" )
else
  echo "[restore] WARNING: no .sha256 sidecar found; skipping checksum verification."
fi

run_sql() { MYSQL_PWD="${DB_PASSWORD}" mysql --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USERNAME}" "$@"; }

if [[ -z "${TARGET_DB}" ]]; then
  # Verify-only: restore into a temporary database and drop it.
  TMP_DB="quenyx_restore_verify_$(date +%s)"
  echo "[restore] VERIFY mode: restoring into temporary DB ${TMP_DB} (live DB untouched)."
  run_sql -e "CREATE DATABASE \`${TMP_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  trap 'MYSQL_PWD="${DB_PASSWORD}" mysql --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USERNAME}" -e "DROP DATABASE IF EXISTS \`'"${TMP_DB}"'\`;" || true' EXIT
  zcat "${BACKUP_FILE}" | run_sql "${TMP_DB}"
  TABLES="$(run_sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${TMP_DB}';")"
  echo "[restore] verification restore succeeded: ${TABLES} table(s) created."
  if [[ "${TABLES}" -lt 1 ]]; then
    echo "ERROR: restored database has no tables — backup is NOT usable." >&2
    exit 1
  fi
  echo "[restore] OK — backup is restorable."
else
  echo "[restore] REAL restore into '${TARGET_DB}'. This OVERWRITES existing data."
  read -r -p "Type the database name to confirm: " CONFIRM
  if [[ "${CONFIRM}" != "${TARGET_DB}" ]]; then
    echo "Aborted." >&2
    exit 1
  fi
  zcat "${BACKUP_FILE}" | run_sql "${TARGET_DB}"
  echo "[restore] OK — restored ${BACKUP_FILE} into ${TARGET_DB}."
fi
