<?php

namespace App\Services\AI;

use App\Contracts\Ai\AiProviderInterface;
use App\Exceptions\Ai\AiProviderException;
use App\Models\Ai\AiProviderSetting;
use App\Models\Project;

/**
 * Single source of truth for whether Quenyx AI may execute against a real provider,
 * which provider key is selected, and what runtime mode the UI should display.
 *
 * Production GA rule: when a real provider (e.g. OpenAI) has platform credentials and AI is not
 * explicitly disabled (AI_ENABLED=false), live execution is allowed automatically. Mock is never
 * used silently in production — only in local/testing, or when AI_MOCK_ALLOWED=true.
 */
class AiExecutionResolver
{
    public const MODE_LIVE = 'live';

    public const MODE_DISABLED = 'disabled';

    public const MODE_NO_PROVIDER = 'no_provider';

    public const MODE_MOCK = 'mock';

    public function __construct(
        private readonly AiProviderRegistry $registry,
    ) {}

    /**
     * Tri-state AI_ENABLED: null = unset (auto-enable when a real provider is configured).
     * Always read from config (never env()) so php artisan config:cache works in production.
     */
    public function explicitEnabledFlag(): ?bool
    {
        $raw = config('ai.feature_flags.enabled');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        return filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function isExplicitlyDisabled(): bool
    {
        return $this->explicitEnabledFlag() === false;
    }

    /**
     * Whether the platform permits live AI execution (ignores mock-only paths).
     */
    public function isExecutionAllowed(): bool
    {
        if ($this->isExplicitlyDisabled()) {
            return false;
        }

        if ($this->explicitEnabledFlag() === true) {
            return $this->hasRunnableRealProvider(null);
        }

        // Unset AI_ENABLED: auto-enable when any real provider is runnable at platform level.
        return $this->hasRunnableRealProvider(null);
    }

    public function allowsMock(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        return filter_var(config('ai.feature_flags.mock_allowed', false), FILTER_VALIDATE_BOOLEAN);
    }

  /**
     * @return self::MODE_*
     */
    public function runtimeMode(?Project $project = null): string
    {
        if ($this->isExplicitlyDisabled()) {
            return self::MODE_DISABLED;
        }

        $key = $this->resolveProviderKey($project, null);

        if ($key === null || $key === '') {
            return self::MODE_NO_PROVIDER;
        }

        if ($key === 'mock') {
            return self::MODE_MOCK;
        }

        if (! $this->isProviderRunnable($project, $key)) {
            return self::MODE_NO_PROVIDER;
        }

        return self::MODE_LIVE;
    }

    public function isLiveExecution(?Project $project = null): bool
    {
        return $this->runtimeMode($project) === self::MODE_LIVE;
    }

    /**
     * Resolve the provider key that would be used (for UI / conversation metadata).
     */
    public function resolveProviderKey(?Project $project = null, ?string $requested = null): ?string
    {
        if (is_string($requested) && $requested !== '') {
            if ($requested === 'mock' && $this->allowsMock()) {
                return 'mock';
            }
            if ($this->isProviderRunnable($project, $requested)) {
                return $requested;
            }

            return null;
        }

        if ($project !== null) {
            $workspaceKey = $this->workspacePreferredProviderKey($project);
            if ($workspaceKey !== null) {
                return $workspaceKey;
            }
        }

        $default = $this->registry->defaultKey();
        if ($default !== '' && $this->isProviderRunnable($project, $default)) {
            return $default;
        }

        foreach ($this->registry->available() as $key) {
            if ($key === 'mock') {
                continue;
            }
            if ($this->isProviderRunnable($project, $key)) {
                return $key;
            }
        }

        if ($this->allowsMock() && ! $this->hasRunnableRealProvider($project)) {
            return 'mock';
        }

        return $default !== '' ? $default : null;
    }

    /**
     * Resolve a provider for a live execution. Never silently returns mock in production.
     *
     * @throws AiProviderException
     */
    public function resolveProvider(?Project $project = null, ?string $requested = null): AiProviderInterface
    {
        if ($this->isExplicitlyDisabled()) {
            throw new AiProviderException(
                'AI execution is disabled by administrator. Set AI_ENABLED=true to enable live AI.',
                'ai_execution_disabled',
                503,
            );
        }

        $key = $this->resolveProviderKey($project, $requested);

        if ($key === null || $key === '') {
            throw new AiProviderException(
                'No AI provider is configured. Set OPENAI_API_KEY and AI_PROVIDER=openai in the server environment.',
                'ai_no_provider',
                503,
            );
        }

        if ($key === 'mock') {
            if (! $this->allowsMock()) {
                throw new AiProviderException(
                    'Mock AI is not available in this environment. Configure a real provider.',
                    'ai_mock_not_allowed',
                    503,
                );
            }

            return $this->registry->get('mock');
        }

        return $this->registry->get($key);
    }

    /**
     * Summary fields for the AI Workspace overview API.
     *
     * @return array<string, mixed>
     */
    public function workspaceSummaryFields(?Project $project = null): array
    {
        $mode = $this->runtimeMode($project);
        $key = $this->resolveProviderKey($project, null);
        $platformOpenAi = $this->registry->isConfigured('openai');

        return [
            'runtime_resolver' => 'v2',
            'runtime_mode' => $mode,
            'ai_enabled' => $mode === self::MODE_LIVE,
            'ai_execution_allowed' => $this->isExecutionAllowed(),
            'explicitly_disabled' => $this->isExplicitlyDisabled(),
            'has_provider' => $key !== null && $key !== '' && $key !== 'mock',
            'default_provider' => ($key !== null && $key !== '' && $key !== 'mock') ? $key : null,
            'executing_provider' => $mode === self::MODE_LIVE ? $key : null,
            'mock_active' => $mode === self::MODE_MOCK,
            'platform_openai_configured' => $platformOpenAi,
        ];
    }

    private function hasRunnableRealProvider(?Project $project): bool
    {
        foreach ($this->registry->available() as $key) {
            if ($key === 'mock') {
                continue;
            }
            if ($this->isProviderRunnable($project, $key)) {
                return true;
            }
        }

        return false;
    }

    private function isProviderRunnable(?Project $project, string $key): bool
    {
        if (! $this->registry->has($key)) {
            return false;
        }

        if ($key === 'mock') {
            return $this->allowsMock();
        }

        if ($project !== null && $this->isWorkspaceProviderExplicitlyDisabled($project, $key)) {
            return false;
        }

        if ($this->registry->isConfigured($key)) {
            return true;
        }

        if ($project !== null) {
            $setting = $this->workspaceSetting($project, $key);
            if ($setting !== null && $setting->enabled && $setting->hasSecret()) {
                return true;
            }
        }

        return false;
    }

    private function workspacePreferredProviderKey(Project $project): ?string
    {
        $registryDefault = $this->registry->defaultKey();
        $settings = AiProviderSetting::query()
            ->where('project_id', $project->id)
            ->get()
            ->keyBy('provider');

        if ($registryDefault !== '') {
            $defaultSetting = $settings->get($registryDefault);
            if ($defaultSetting !== null && $defaultSetting->enabled && $this->isProviderRunnable($project, $registryDefault)) {
                return $registryDefault;
            }
            if ($defaultSetting === null && $this->isProviderRunnable($project, $registryDefault)) {
                return $registryDefault;
            }
        }

        foreach ($settings as $key => $setting) {
            if ($setting->enabled && $this->isProviderRunnable($project, (string) $key)) {
                return (string) $key;
            }
        }

        return null;
    }

    private function isWorkspaceProviderExplicitlyDisabled(Project $project, string $key): bool
    {
        $setting = $this->workspaceSetting($project, $key);

        return $setting !== null && $setting->enabled === false;
    }

    private function workspaceSetting(Project $project, string $key): ?AiProviderSetting
    {
        return AiProviderSetting::query()
            ->where('project_id', $project->id)
            ->where('provider', $key)
            ->first();
    }
}
