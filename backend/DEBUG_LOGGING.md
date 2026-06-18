# Debug Logging Instructions

If the Laravel log file (`storage/logs/laravel.log`) is empty but you're seeing 500 errors, or the **AI Agent** shows repeated "Permission denied" / `laravel.log` messages, fix storage permissions first.

## 1. Check Log File Permissions

```bash
cd /var/www/quenyx-saas/backend   # or /var/www/quenyx/quenyx-saas/backend
sudo mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
ls -la storage/logs/
```

Restart the backend after fixing permissions:

```bash
sudo systemctl restart quenyx-backend
php artisan config:clear
```

## 2. Check PHP Error Log

Check the PHP error log (usually in `/var/log/php/` or `/var/log/nginx/error.log`):

```bash
tail -f /var/log/php/error.log
# or
tail -f /var/log/nginx/error.log
```

## 3. Enable Debug Mode

In `.env` file:
```
APP_DEBUG=true
LOG_LEVEL=debug
```

Then clear config cache:
```bash
php artisan config:clear
php artisan cache:clear
```

## 4. Test Logging Directly

Create a test route to verify logging works:

```php
Route::get('/test-log', function() {
    \Log::info('Test log entry');
    \Log::error('Test error entry');
    return response()->json(['success' => true, 'message' => 'Check laravel.log']);
});
```

## 5. Check Database Connection

The 500 error might be a database connection issue. Verify:

```bash
php artisan tinker
>>> DB::connection()->getPdo();
```

If this fails, check your `.env` database configuration.

## 6. Check for Syntax Errors

```bash
php artisan route:list
php -l app/Http/Controllers/ProjectController.php
```

## 7. Check Web Server Error Logs

If using Nginx:
```bash
tail -f /var/log/nginx/error.log
```

If using Apache:
```bash
tail -f /var/log/apache2/error.log
```
