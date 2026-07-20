-- Quenyx MySQL bootstrap — LOCAL DEVELOPMENT / STAGING / CI (not production GA).
--
-- Creates: quenyx_dev (app), quenyx_test (PHPUnit only)
--
-- BEFORE RUNNING:
--   1. Replace both CHANGE_ME below with your dev password.
--   2. mysql -u root -p < scripts/mysql-quenyx-setup-dev.sql
--
-- Production servers should use mysql-quenyx-setup-production.sql (database quenyx only).

CREATE DATABASE IF NOT EXISTS quenyx_dev
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS quenyx_test
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'quenyx'@'localhost' IDENTIFIED BY 'CHANGE_ME';
CREATE USER IF NOT EXISTS 'quenyx'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME';

GRANT ALL PRIVILEGES ON quenyx_dev.* TO 'quenyx'@'localhost';
GRANT ALL PRIVILEGES ON quenyx_dev.* TO 'quenyx'@'127.0.0.1';
GRANT ALL PRIVILEGES ON quenyx_test.* TO 'quenyx'@'localhost';
GRANT ALL PRIVILEGES ON quenyx_test.* TO 'quenyx'@'127.0.0.1';

FLUSH PRIVILEGES;
