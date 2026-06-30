<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * GA remediation: production configuration validation.
 *
 * Run during deploy/CI (`php artisan quenyx:config-check`) to fail fast when the
 * environment is misconfigured for production: debug left on, missing app key,
 * permissive CORS, weak session/secret config, or an unreachable database.
 * Returns a non-zero exit code when any blocking issue is found.
 */
class ConfigCheckCommand extends Command
{
    protected $signature = 'quenyx:config-check {--strict : Treat warnings as failures}';

    protected $description = 'Validate runtime configuration for production readiness';

    public function handle(): int
    {
        $errors = [];
        $warnings = [];

        $isProd = app()->environment('production');

        if (empty(config('app.key'))) {
            $errors[] = 'APP_KEY is not set (run php artisan key:generate).';
        }

        if ($isProd && config('app.debug')) {
            $errors[] = 'APP_DEBUG is true in production — must be false.';
        }

        if ($isProd && ! str_starts_with((string) config('app.url'), 'https://')) {
            $warnings[] = 'APP_URL is not HTTPS in production.';
        }

        // CORS: wildcard origin is not acceptable in production.
        $origins = (array) config('cors.allowed_origins', []);
        if ($isProd && in_array('*', $origins, true)) {
            $errors[] = 'CORS allowed_origins is "*" in production — set CORS_ALLOWED_ORIGINS to an explicit allowlist.';
        }
        if ($isProd && (bool) config('cors.supports_credentials') && in_array('*', $origins, true)) {
            $errors[] = 'CORS supports_credentials=true with wildcard origin is invalid.';
        }

        // Sanctum token expiration should be enabled.
        if (config('sanctum.expiration') === null) {
            $warnings[] = 'Sanctum token expiration is disabled (config/sanctum.php expiration = null).';
        }

        // Security headers should be enabled in production.
        if ($isProd && ! config('security.headers_enabled', true)) {
            $warnings[] = 'Security response headers are disabled in production.';
        }

        // Gateway internal secret must be present (deployment relies on it).
        if ($isProd && empty(env('GATEWAY_INTERNAL_SECRET'))) {
            $warnings[] = 'GATEWAY_INTERNAL_SECRET is empty.';
        }

        // Session security in production.
        if ($isProd && ! config('session.secure')) {
            $warnings[] = 'SESSION_SECURE_COOKIE is not enabled in production.';
        }

        // Database connectivity.
        try {
            DB::connection()->getPdo();
            $this->line('  <info>OK</info> database connection');
        } catch (\Throwable $e) {
            $errors[] = 'Database connection failed: '.$e->getMessage();
        }

        foreach ($warnings as $w) {
            $this->warn('  WARN  '.$w);
        }
        foreach ($errors as $e) {
            $this->error('  FAIL  '.$e);
        }

        if ($errors !== []) {
            $this->error(sprintf('Config check FAILED with %d error(s).', count($errors)));

            return self::FAILURE;
        }

        if ($warnings !== [] && $this->option('strict')) {
            $this->error(sprintf('Config check failed in --strict mode with %d warning(s).', count($warnings)));

            return self::FAILURE;
        }

        $this->info('Config check passed'.($warnings !== [] ? ' (with warnings)' : '').'.');

        return self::SUCCESS;
    }
}
