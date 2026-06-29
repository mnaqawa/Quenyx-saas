<?php

namespace App\Models\Ai;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sprint 20 — per-workspace provider preference.
 *
 * The `settings` attribute is transparently encrypted at rest (Laravel `encrypted:array` cast):
 * any sensitive override (e.g. an org key) is never persisted in plain text. Callers/resources
 * must NEVER expose raw secret values — only whether a secret is configured.
 */
class AiProviderSetting extends Model
{
    protected $table = 'ai_provider_settings';

    protected $fillable = [
        'uuid',
        'project_id',
        'updated_by',
        'provider',
        'enabled',
        'model',
        'settings',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'settings' => 'encrypted:array',
    ];

    /** Settings keys treated as secrets — never returned by the API. */
    public const SECRET_KEYS = ['api_key', 'organization', 'secret', 'token'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Whether any secret value is configured (without revealing it).
     */
    public function hasSecret(): bool
    {
        $settings = (array) ($this->settings ?? []);
        foreach (self::SECRET_KEYS as $key) {
            if (! empty($settings[$key])) {
                return true;
            }
        }

        return false;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
