-- Move data from mistaken dev-style DB names to production `quenyx`.
-- Run ONLY if quenyx_dev exists and quenyx is empty or new.
-- From shell (replace passwords):
--
--   mysql -u root -p < scripts/mysql-quenyx-rename-to-production.sql
--
-- Or if quenyx_dev has data and quenyx does not exist yet:
--   mysqldump -u root -p quenyx_dev | mysql -u root -p quenyx
--
-- After Laravel works with DB_DATABASE=quenyx, drop dev databases on production:
--   DROP DATABASE quenyx_dev;
--   DROP DATABASE quenyx_test;

CREATE DATABASE IF NOT EXISTS quenyx
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Grants (user quenyx must already exist from bootstrap)
GRANT ALL PRIVILEGES ON quenyx.* TO 'quenyx'@'localhost';
GRANT ALL PRIVILEGES ON quenyx.* TO 'quenyx'@'127.0.0.1';
FLUSH PRIVILEGES;

-- Optional one-liner from bash when quenyx_dev has all tables:
-- mysqldump -u root -p --single-transaction quenyx_dev | mysql -u root -p quenyx
