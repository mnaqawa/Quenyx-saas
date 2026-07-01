<?php

namespace App\Console\Commands;

use App\Services\AI\AiExecutionResolver;
use App\Services\AI\AiProviderRegistry;
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
        if ($isProd && empty(config('app.gateway_internal_secret'))) {
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

        $this->validateAiConfig($isProd, $errors, $warnings);

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

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function validateAiConfig(bool $isProd, array &$errors, array &$warnings): void
    {
        $registry = app(AiProviderRegistry::class);
        $execution = app(AiExecutionResolver::class);

        $provider = is_string(config('ai.default')) && config('ai.default') !== ''
            ? (string) config('ai.default')
            : $registry->defaultKey();

        $mode = $execution->runtimeMode();

        if ($execution->isExplicitlyDisabled()) {
            $this->line('  <info>OK</info> AI execution disabled by administrator (AI_ENABLED=false).');

            return;
        }

        $openAiKey = (string) config('ai.providers.openai.api_key', '');

        if ($mode === AiExecutionResolver::MODE_LIVE) {
            $key = $execution->resolveProviderKey();
            $this->line('  <info>OK</info> AI live execution ('.$key.')');

            if ($key === 'openai' && $openAiKey === '') {
                $errors[] = 'AI provider is openai but OPENAI_API_KEY is missing from config.';
            }

            if ($key !== '' && $key !== 'mock') {
                $vectorStoreId = trim((string) config('openai.vector_store_id', ''));
                if (
                    (bool) config('ai.workspace.knowledge_enabled', true)
                    && $vectorStoreId === ''
                ) {
                    $warnings[] = 'OPENAI_VECTOR_STORE_ID is not set — workspace chat and Ask Quenyx AI knowledge base will not work.';
                }
            }

            if ($key !== '' && $key !== 'mock' && ! $registry->has($key)) {
                $errors[] = "AI provider '{$key}' has no executable adapter.";
            }

            return;
        }

        if ($mode === AiExecutionResolver::MODE_MOCK) {
            if ($isProd && ! $execution->allowsMock()) {
                $errors[] = 'Mock AI is active in production without AI_MOCK_ALLOWED — configure OPENAI_API_KEY or set AI_ENABLED=false.';
            } elseif ($isProd) {
                $warnings[] = 'Mock AI is allowed in production (AI_MOCK_ALLOWED=true or misconfiguration).';
            } else {
                $this->line('  <info>OK</info> AI mock mode (local/testing).');
            }

            return;
        }

        if ($mode === AiExecutionResolver::MODE_NO_PROVIDER) {
            if ($isProd) {
                if ($this->option('strict')) {
                    $errors[] = 'No AI provider configured in production (set OPENAI_API_KEY and AI_PROVIDER=openai).';
                } else {
                    $warnings[] = 'No AI provider configured (set OPENAI_API_KEY and AI_PROVIDER=openai for live AI).';
                }
            } else {
                $this->line('  <info>OK</info> No AI provider configured.');
            }

            if ($provider === 'openai' && $openAiKey === '') {
                $errors[] = 'AI_PROVIDER=openai requires OPENAI_API_KEY in backend/.env (then config:cache).';
            }
        }
    }
}
