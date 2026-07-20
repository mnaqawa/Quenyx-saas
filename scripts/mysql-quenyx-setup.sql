-- Quenyx MySQL bootstrap — PRODUCTION (default entry point).
--
-- Database name: quenyx  (not quenyx_dev / quenyx_test on production)
--
-- Local development / PHPUnit instead:
--   mysql -u root -p < scripts/mysql-quenyx-setup-dev.sql
--
-- BEFORE RUNNING: replace both CHANGE_ME with backend/.env DB_PASSWORD, then:
--   mysql -u root -p < scripts/mysql-quenyx-setup.sql

CREATE DATABASE IF NOT EXISTS quenyx
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'quenyx'@'localhost' IDENTIFIED BY 'CHANGE_ME';
CREATE USER IF NOT EXISTS 'quenyx'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME';

GRANT ALL PRIVILEGES ON quenyx.* TO 'quenyx'@'localhost';
GRANT ALL PRIVILEGES ON quenyx.* TO 'quenyx'@'127.0.0.1';

FLUSH PRIVILEGES;

-- If you already created quenyx_dev on this host by mistake, see DEPLOYMENT.md §1b
-- "Rename / migrate from quenyx_dev to quenyx".
