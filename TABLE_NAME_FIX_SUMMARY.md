# Observe Targets Table Name Fix Summary

## Issue

Database tables use plural names (`observe_targets_hosts`, `observe_targets_services`) but code was querying singular names (`observe_target_hosts`, `observe_target_services`), causing SQL errors.

## Fixes Applied

### 1. Model Table Names
- **`backend/app/Models/ObserveTargetHost.php`**
  - Added: `protected $table = 'observe_targets_hosts';`

- **`backend/app/Models/ObserveTargetService.php`**
  - Added: `protected $table = 'observe_targets_services';`

### 2. Schema Checks
- **`backend/app/Services/NagiosConfigPublisher.php`**
  - Changed: `Schema::hasTable('observe_target_hosts')` → `Schema::hasTable('observe_targets_hosts')`

- **`backend/app/Console/Commands/PublishNagiosConfig.php`**
  - Changed: `Schema::hasTable('observe_target_hosts')` → `Schema::hasTable('observe_targets_hosts')`

### 3. Feature Test Added
- **`backend/tests/Feature/ObserveTest.php`**
  - Added: `test_put_targets_persists_and_publish_reads_back()`
  - Tests:
    - PUT targets persists correctly
    - Services are associated with hosts
    - Publish can read back targets without SQL errors
    - Verifies table names are correct

## Verification

### Before Fix
```bash
# Would fail with: SQLSTATE[42S02]: Table 'quenyx_dev.observe_target_hosts' doesn't exist
curl -X PUT "http://127.0.0.1:8081/api/workspaces/84/observe/targets" ...
php artisan observe:nagios:publish --workspace_id=84
```

### After Fix
```bash
# Should succeed
TOKEN=$(curl -s -X POST http://127.0.0.1:8081/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@quenyx.test","password":"Password123!"}' | jq -r '.data.token')

curl -s -X PUT "http://127.0.0.1:8081/api/workspaces/84/observe/targets" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"hosts":[{"name":"web-01","address":"127.0.0.1","check_command":"check-host-alive","enabled":true,"services":[{"name":"HTTP","check_command":"check_http","enabled":true}]}]}' | jq

# Expected: {"success":true,"message":"Targets updated and published to Nagios"}

php artisan observe:nagios:publish --workspace_id=84

# Expected: "Config published successfully" (no SQL errors)
```

### Run Tests
```bash
cd backend
php artisan test --filter=ObserveTest

# Expected: All tests pass, including new test_put_targets_persists_and_publish_reads_back
```

## Modified Files

1. `backend/app/Models/ObserveTargetHost.php` - Added `$table` property
2. `backend/app/Models/ObserveTargetService.php` - Added `$table` property
3. `backend/app/Services/NagiosConfigPublisher.php` - Fixed table name in Schema check
4. `backend/app/Console/Commands/PublishNagiosConfig.php` - Fixed table name in Schema check
5. `backend/tests/Feature/ObserveTest.php` - Added feature test

## Summary

All table name references now match the actual database table names (plural). Models, Schema checks, and tests all use the correct `observe_targets_hosts` and `observe_targets_services` table names. PUT targets and publish operations should now work without SQL errors.
