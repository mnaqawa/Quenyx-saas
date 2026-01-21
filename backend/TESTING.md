# Running Tests

This project uses PHPUnit for testing. Since Laravel 10 doesn't include the `php artisan test` command by default, use PHPUnit directly.

## Running All Tests

```bash
vendor/bin/phpunit
```

or

```bash
./vendor/bin/phpunit
```

## Running Specific Test Classes

```bash
# Run ProfileTest only
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
- SQLite in-memory database (or `testing` connection)
- Array cache driver
- Array session driver
- Array mail driver

Make sure your `.env` file has a `DB_CONNECTION=testing` entry, or the tests will use the default database connection.
