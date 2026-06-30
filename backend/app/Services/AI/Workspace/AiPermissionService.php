<?php

namespace App\Services\AI\Workspace;

use App\Models\Ai\AiWorkspacePermission;
use App\Models\Project;
use App\Models\User;

/**
 * Sprint 20 — manages the additive per-workspace AI capability matrix. Reads return the EFFECTIVE
 * matrix per role (override merged over role defaults); writes upsert overrides and are audited.
 */
class AiPermissionService
{
    public function __construct(private readonly AiWorkspaceAuditLogger $audit) {}

    /**
     * Effective AI permission matrix for every role in the workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function matrix(Project $project): array
    {
        $overrides = AiWorkspacePermission::query()
            ->where('project_id', $project->id)
            ->get()
            ->keyBy('role');

        $out = [];
        foreach (AiWorkspacePermission::ROLES as $role) {
            $defaults = AiWorkspaceContextResolver::defaultsForRole($role);
            /** @var AiWorkspacePermission|null $override */
            $override = $overrides->get($role);

            $out[] = [
                'role' => $role,
                'source' => $override ? 'override' : 'default',
                'can_use_ai' => $override?->can_use_ai ?? $defaults['can_use_ai'],
                'can_manage_providers' => $override?->can_manage_providers ?? $defaults['can_manage_providers'],
                'can_manage_templates' => $override?->can_manage_templates ?? $defaults['can_manage_templates'],
                'can_view_costs' => $override?->can_view_costs ?? $defaults['can_view_costs'],
                'can_administer' => $override?->can_administer ?? $defaults['can_administer'],
            ];
        }

        return $out;
    }

    /**
     * Upsert overrides for one or more roles.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    public function update(Project $project, User $user, array $rules): array
    {
        foreach ($rules as $rule) {
            $role = (string) ($rule['role'] ?? '');
            if (! in_array($role, AiWorkspacePermission::ROLES, true)) {
                continue;
            }

            $defaults = AiWorkspaceContextResolver::defaultsForRole($role);

            AiWorkspacePermission::query()->updateOrCreate(
                ['project_id' => $project->id, 'role' => $role],
                [
                    'updated_by' => $user->id,
                    'can_use_ai' => (bool) ($rule['can_use_ai'] ?? $defaults['can_use_ai']),
                    'can_manage_providers' => (bool) ($rule['can_manage_providers'] ?? $defaults['can_manage_providers']),
                    'can_manage_templates' => (bool) ($rule['can_manage_templates'] ?? $defaults['can_manage_templates']),
                    'can_view_costs' => (bool) ($rule['can_view_costs'] ?? $defaults['can_view_costs']),
                    'can_administer' => (bool) ($rule['can_administer'] ?? $defaults['can_administer']),
                ],
            );
        }

        $this->audit->record($user, $project, 'ai_permissions_updated', [
            'roles' => array_values(array_filter(array_map(static fn ($r) => $r['role'] ?? null, $rules))),
        ]);

        return $this->matrix($project);
    }
}
