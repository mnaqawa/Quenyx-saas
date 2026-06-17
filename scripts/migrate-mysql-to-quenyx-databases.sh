#!/usr/bin/env bash
# Copy data from an existing MySQL database into quenyx_dev / quenyx_test.
# Usage (on the app server):
#   export MYSQL_ROOT_USER=root
#   export LEGACY_DEV_DB=your_current_dev_db
#   export LEGACY_TEST_DB=your_current_test_db   # optional
#   ./scripts/migrate-mysql-to-quenyx-databases.sh
set -euo pipefail

MYSQL_ROOT_USER="${MYSQL_ROOT_USER:-root}"
LEGACY_DEV_DB="${LEGACY_DEV_DB:?Set LEGACY_DEV_DB to your current development database name}"
LEGACY_TEST_DB="${LEGACY_TEST_DB:-}"
TMP_DIR="${TMP_DIR:-/tmp/quenyx-mysql-migrate}"

mkdir -p "$TMP_DIR"

echo "==> Creating quenyx_dev and quenyx_test (if missing)"
mysql -u "$MYSQL_ROOT_USER" -p -e "
CREATE DATABASE IF NOT EXISTS quenyx_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS quenyx_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"

echo "==> Dumping ${LEGACY_DEV_DB} -> quenyx_dev"
mysqldump -u "$MYSQL_ROOT_USER" -p --single-transaction --routines --triggers "$LEGACY_DEV_DB" \
  | mysql -u "$MYSQL_ROOT_USER" -p quenyx_dev

if [[ -n "$LEGACY_TEST_DB" ]]; then
  echo "==> Dumping ${LEGACY_TEST_DB} -> quenyx_test"
  mysqldump -u "$MYSQL_ROOT_USER" -p --single-transaction --routines --triggers "$LEGACY_TEST_DB" \
    | mysql -u "$MYSQL_ROOT_USER" -p quenyx_test
fi

echo "==> Done. Update backend/.env:"
echo "    DB_DATABASE=quenyx_dev"
echo "    DB_USERNAME=quenyx"
echo "Then: cd backend && php artisan config:clear && php artisan migrate --force"
echo "After verification, drop legacy databases manually if no longer needed."
