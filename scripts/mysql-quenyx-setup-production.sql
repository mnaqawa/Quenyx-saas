-- Quenyx MySQL bootstrap — PRODUCTION (single application database).
--
-- Creates database: quenyx  (no _dev / _test suffix on production hosts)
--
-- BEFORE RUNNING:
--   1. Replace both CHANGE_ME below with one strong password (backend/.env DB_PASSWORD).
--   2. From repo root:
--        mysql -u root -p < scripts/mysql-quenyx-setup-production.sql
--
-- Do NOT create quenyx_test on production; PHPUnit uses quenyx_test only on dev/CI
-- (see scripts/mysql-quenyx-setup-dev.sql and backend/TESTING.md).

CREATE DATABASE IF NOT EXISTS quenyx
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'quenyx'@'localhost' IDENTIFIED BY 'CHANGE_ME';
CREATE USER IF NOT EXISTS 'quenyx'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME';

GRANT ALL PRIVILEGES ON quenyx.* TO 'quenyx'@'localhost';
GRANT ALL PRIVILEGES ON quenyx.* TO 'quenyx'@'127.0.0.1';

FLUSH PRIVILEGES;
