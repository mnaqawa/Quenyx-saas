<?php

namespace App\Services\Ai\Workspace;

use App\Models\Ai\AiProviderSetting;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AiProviderRegistry;
use Ramsey\Uuid\Uuid;

/**
 * Sprint 20 — per-workspace provider preferences over the config-driven AiProviderRegistry.
 *
 * Providers are addressed by a DETERMINISTIC UUIDv5 derived from (workspace uuid + provider key),
 * so every provider is UUID-addressable whether or not a settings row exists yet — numeric IDs are
 * never exposed. Secret values are stored only inside the model's encrypted `settings` blob and are
 * NEVER returned (the API exposes a boolean "secret configured" indicator instead).
 */
class AiProviderSettingsService
{
    private const PROVIDER_NAMESPACE = '6f9619ff-8b86-d011-b42d-00cf4fc964ff';

    public function __construct(
        private readonly AiProviderRegistry $registry,
        private readonly AiWorkspaceAuditLogger $audit,
    ) {}

    public function providerUuid(Project $project, string $providerKey): string
    {
        return Uuid::uuid5(self::PROVIDER_NAMESPACE, "quenyx:ai:provider:{$project->uuid}:{$providerKey}")->toString();
    }

    /**
     * Resolve a provider key from its deterministic UUID for this workspace, or null if unknown.
     */
    public function resolveProviderKey(Project $project, string $uuid): ?string
    {
        foreach ($this->registry->available() as $key) {
            if (hash_equals($this->providerUuid($project, $key), $uuid)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * List all known providers merged with this workspace's saved preferences (no secrets).
     *
     * @return list<array<string, mixed>>
     */
    public function list(Project $project): array
    {
        $defaultKey = $this->registry->defaultKey();

        $saved = AiProviderSetting::query()
            ->where('project_id', $project->id)
            ->get()
            ->keyBy('provider');

        $out = [];
        foreach ($this->registry->available() as $key) {
            /** @var AiProviderSetting|null $setting */
            $setting = $saved->get($key);

            $out[] = [
                'uuid' => $this->providerUuid($project, $key),
                'provider' => $key,
                'is_default' => $key === $defaultKey,
                'implemented' => $this->registry->has($key),
                'enabled' => $setting?->enabled ?? $this->registry->has($key),
                'model' => $setting?->model,
                'secret_configured' => $setting?->hasSecret() ?? false,
                'configured' => $setting !== null,
                'updated_at' => $setting?->updated_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * Upsert a workspace's preferences for a provider. Secret keys (api_key, organization) are
     * merged into the encrypted settings blob only when explicitly provided.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>  the public (secret-free) representation
     */
    public function update(Project $project, User $user, string $providerKey, array $data): array
    {
        /** @var AiProviderSetting $setting */
        $setting = AiProviderSetting::query()
            ->where('project_id', $project->id)
            ->where('provider', $providerKey)
            ->first() ?? new AiProviderSetting([
                'project_id' => $project->id,
                'provider' => $providerKey,
            ]);

        $setting->project_id = $project->id;
        $setting->provider = $providerKey;
        $setting->updated_by = $user->id;

        if (array_key_exists('enabled', $data)) {
            $setting->enabled = (bool) $data['enabled'];
        }
        if (array_key_exists('model', $data)) {
            $setting->model = $data['model'] !== '' ? $data['model'] : null;
        }

        $settings = (array) ($setting->settings ?? []);
        foreach (AiProviderSetting::SECRET_KEYS as $secretKey) {
            if (array_key_exists($secretKey, $data) && is_string($data[$secretKey]) && $data[$secretKey] !== '') {
                $settings[$secretKey] = $data[$secretKey];
            }
        }
        if (! empty($data['clear_secrets'])) {
            $settings = [];
        }
        $setting->settings = $settings === [] ? null : $settings;

        $setting->save();

        $this->audit->record($user, $project, 'ai_provider_settings_updated', [
            'provider' => $providerKey,
            'enabled' => $setting->enabled,
            'model' => $setting->model,
            'secret_configured' => $setting->hasSecret(),
        ]);

        return [
            'uuid' => $this->providerUuid($project, $providerKey),
            'provider' => $providerKey,
            'is_default' => $providerKey === $this->registry->defaultKey(),
            'implemented' => $this->registry->has($providerKey),
            'enabled' => $setting->enabled,
            'model' => $setting->model,
            'secret_configured' => $setting->hasSecret(),
            'configured' => true,
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ];
    }
}
