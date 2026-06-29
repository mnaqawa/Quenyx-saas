<?php

namespace App\Models\Ai;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sprint 20 — additive per-workspace, per-role AI capability override. Absence of a row means the
 * base ProjectPolicy role defaults apply.
 */
class AiWorkspacePermission extends Model
{
    protected $table = 'ai_workspace_permissions';

    protected $fillable = [
        'uuid',
        'project_id',
        'updated_by',
        'role',
        'can_use_ai',
        'can_manage_providers',
        'can_manage_templates',
        'can_view_costs',
        'can_administer',
    ];

    protected $casts = [
        'can_use_ai' => 'boolean',
        'can_manage_providers' => 'boolean',
        'can_manage_templates' => 'boolean',
        'can_view_costs' => 'boolean',
        'can_administer' => 'boolean',
    ];

    public const ROLES = ['owner', 'admin', 'member', 'viewer'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
