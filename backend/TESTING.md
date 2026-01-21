# Running Tests

This project uses PHPUnit for testing. Since Laravel 10 doesn't include the `php artisan test` command by default, use PHPUnit directly.

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
- SQLite in-memory database (`:memory:`)
- Array cache driver
- Array session driver
- Array mail driver

The tests use `RefreshDatabase` trait, which automatically creates and migrates a fresh database for each test.

## Troubleshooting

If `vendor/bin/phpunit` doesn't exist:
1. Make sure you've run `composer install` to install dev dependencies
2. Check that `phpunit/phpunit` is listed in `composer.json` under `require-dev`
3. Verify the vendor directory exists and contains the bin folder
