# Running Tests

This project uses PHPUnit. Use either:

```bash
php artisan test
```

or PHPUnit directly:

```bash
vendor/bin/phpunit
```

## Prerequisites

Make sure dev dependencies are installed:

```bash
composer install
```

or if you only want to install dev dependencies:

```bash
composer install --dev
```

This will install PHPUnit and other testing dependencies.

## Database Setup for Tests

Tests require a database. You have two options:

### Option 1: Install SQLite (Recommended for Testing)

Install the SQLite PDO extension:

**Ubuntu/Debian:**
```bash
sudo apt-get update
sudo apt-get install php-sqlite3
# Or for PHP 8.1+
sudo apt-get install php8.1-sqlite3
```

**CentOS/RHEL:**
```bash
sudo yum install php-pdo php-sqlite3
# Or for PHP 8.1+
sudo yum install php81-php-pdo php81-php-sqlite3
```

After installation, restart PHP-FPM:
```bash
sudo systemctl restart php-fpm
# Or
sudo systemctl restart php8.1-fpm
```

Then update `phpunit.xml` to use SQLite:
```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

### Option 2: Use MySQL Test Database

1. Create a separate test database:
```bash
mysql -u root -p
CREATE DATABASE quenyx_test;
EXIT;
```

2. Update `phpunit.xml` with your MySQL credentials:
```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="quenyx_test"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="3306"/>
<env name="DB_USERNAME" value="your_username"/>
<env name="DB_PASSWORD" value="your_password"/>
```

**⚠️ WARNING:** Never use your production database for tests! Always use a separate test database.

## Running All Tests

```bash
php artisan test
```

or

```bash
vendor/bin/phpunit
```

## Running Specific Test Classes

```bash
php artisan test --filter=ProfileTest
# or
vendor/bin/phpunit --filter ProfileTest

# Run ProjectMembershipTest only
vendor/bin/phpunit --filter ProjectMembershipTest
```

## Running Specific Test Methods

```bash
# Run a specific test method
vendor/bin/phpunit --filter test_authenticated_user_can_get_profile

# Run tests matching a pattern
vendor/bin/phpunit --filter "test_owner_can"
```

## Running Tests with Coverage

```bash
vendor/bin/phpunit --coverage-html coverage
```

## Test Configuration

Tests are configured in `phpunit.xml`. The test environment uses:
- Separate test database (SQLite in-memory or MySQL test database)
- Array cache driver
- Array session driver
- Array mail driver

The tests use `RefreshDatabase` trait, which automatically creates and migrates a fresh database for each test.

## Troubleshooting

### "could not find driver" Error

This means the SQLite PDO extension is not installed. Install it using the commands above, or switch to MySQL by updating `phpunit.xml`.

### "Access denied" for MySQL

Make sure:
1. The test database exists
2. The MySQL user has permissions to create/drop tables
3. The credentials in `phpunit.xml` are correct

### Tests are slow

If using MySQL, consider switching to SQLite in-memory database for faster test execution.
