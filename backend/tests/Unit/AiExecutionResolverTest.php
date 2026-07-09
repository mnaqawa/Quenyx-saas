<?php

namespace Tests\Unit;

use App\Exceptions\Ai\AiProviderException;
use App\Services\AI\AiExecutionResolver;
use App\Services\AI\AiProviderRegistry;
use Tests\TestCase;

class AiExecutionResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearAiEnv();
        parent::tearDown();
    }

    public function test_production_openai_configured_selects_live_provider(): void
    {
        $this->simulateProduction();
        config(['ai.providers.openai.api_key' => 'sk-test-key']);
        config(['ai.default' => 'openai']);

        $resolver = app(AiExecutionResolver::class);

        $this->assertSame(AiExecutionResolver::MODE_LIVE, $resolver->runtimeMode());
        $this->assertSame('openai', $resolver->resolveProviderKey());
        $this->assertTrue($resolver->isLiveExecution());
        $this->assertSame('openai', $resolver->resolveProvider()->key());
    }

    public function test_production_ai_disabled_returns_disabled_mode(): void
    {
        $this->simulateProduction();
        config(['ai.providers.openai.api_key' => 'sk-test-key']);
        config(['ai.feature_flags.enabled' => false]);

        $resolver = app(AiExecutionResolver::class);

        $this->assertSame(AiExecutionResolver::MODE_DISABLED, $resolver->runtimeMode());
        $this->expectException(AiProviderException::class);
        $resolver->resolveProvider();
    }

    public function test_production_no_provider_returns_no_provider_mode(): void
    {
        $this->simulateProduction();
        config(['ai.providers.openai.api_key' => null]);
        config(['ai.default' => null]);
        $this->clearAiEnv();

        $resolver = app(AiExecutionResolver::class);

        $this->assertSame(AiExecutionResolver::MODE_NO_PROVIDER, $resolver->runtimeMode());
        $this->expectException(AiProviderException::class);
        $resolver->resolveProvider();
    }

    public function test_testing_without_provider_allows_mock(): void
    {
        $this->resetTestingAiConfig();

        $resolver = app(AiExecutionResolver::class);

        $this->assertSame(AiExecutionResolver::MODE_MOCK, $resolver->runtimeMode());
        $this->assertSame('mock', $resolver->resolveProvider()->key());
    }

    public function test_workspace_summary_fields_reflect_runtime_mode(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test']);
        config(['ai.default' => 'openai']);
        config(['ai.feature_flags.enabled' => null]);

        $fields = app(AiExecutionResolver::class)->workspaceSummaryFields();

        $this->assertSame(AiExecutionResolver::MODE_LIVE, $fields['runtime_mode']);
        $this->assertTrue($fields['ai_enabled']);
        $this->assertSame('openai', $fields['executing_provider']);
        $this->assertTrue($fields['platform_openai_configured']);
    }

    public function test_provider_settings_metadata_counts_platform_credentials(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test']);
        $registry = new AiProviderRegistry();

        $this->assertTrue($registry->isConfigured('openai'));
        $this->assertSame('openai', $registry->defaultKey());
    }

    private function simulateProduction(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);
    }

    private function clearAiEnv(): void
    {
        putenv('OPENAI_API_KEY');
        unset($_ENV['OPENAI_API_KEY'], $_SERVER['OPENAI_API_KEY']);
        putenv('AI_PROVIDER');
        unset($_ENV['AI_PROVIDER'], $_SERVER['AI_PROVIDER']);
        putenv('AI_ENABLED');
        unset($_ENV['AI_ENABLED'], $_SERVER['AI_ENABLED']);
        putenv('AI_MOCK_ALLOWED');
        unset($_ENV['AI_MOCK_ALLOWED'], $_SERVER['AI_MOCK_ALLOWED']);
        config([
            'ai.default' => null,
            'ai.feature_flags.enabled' => null,
            'ai.providers.openai.api_key' => null,
        ]);
    }
}
