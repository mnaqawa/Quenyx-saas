-- Quenyx MySQL bootstrap (fresh install).
-- Replace @quenyx_password with a strong password before running.
-- Run: mysql -u root -p < scripts/mysql-quenyx-setup.sql

SET @quenyx_password = 'CHANGE_ME';

CREATE DATABASE IF NOT EXISTS quenyx_dev
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS quenyx_test
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'quenyx'@'localhost' IDENTIFIED BY @quenyx_password;
CREATE USER IF NOT EXISTS 'quenyx'@'127.0.0.1' IDENTIFIED BY @quenyx_password;

GRANT ALL PRIVILEGES ON quenyx_dev.* TO 'quenyx'@'localhost';
GRANT ALL PRIVILEGES ON quenyx_dev.* TO 'quenyx'@'127.0.0.1';
GRANT ALL PRIVILEGES ON quenyx_test.* TO 'quenyx'@'localhost';
GRANT ALL PRIVILEGES ON quenyx_test.* TO 'quenyx'@'127.0.0.1';

FLUSH PRIVILEGES;
