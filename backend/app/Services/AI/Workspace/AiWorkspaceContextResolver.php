<?php

namespace App\Services\AI\Workspace;

use App\Models\Ai\AiWorkspacePermission;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sprint 20 — resolves the workspace (Project) for the platform-level, non-nested Unified AI
 * Workspace APIs from a required `workspace` UUID (query for reads, body for writes, or an
 * `X-Workspace-Id` header). Also resolves the caller's effective AI capability matrix by merging
 * base role defaults with any per-workspace AiWorkspacePermission overrides.
 *
 * This is the single boundary that turns an opaque workspace UUID into a Project — numeric IDs are
 * never accepted or exposed by these APIs.
 */
class AiWorkspaceContextResolver
{
    /**
     * Default AI capability matrix per workspace role. Overridable per workspace via
     * ai_workspace_permissions. Viewers are read-only (cannot run AI by default).
     *
     * @var array<string, array<string, bool>>
     */
    private const ROLE_DEFAULTS = [
        'owner' => [
            'can_use_ai' => true, 'can_manage_providers' => true, 'can_manage_templates' => true,
            'can_view_costs' => true, 'can_administer' => true,
        ],
        'admin' => [
            'can_use_ai' => true, 'can_manage_providers' => true, 'can_manage_templates' => true,
            'can_view_costs' => true, 'can_administer' => true,
        ],
        'member' => [
            'can_use_ai' => true, 'can_manage_providers' => false, 'can_manage_templates' => true,
            'can_view_costs' => false, 'can_administer' => false,
        ],
        'viewer' => [
            'can_use_ai' => false, 'can_manage_providers' => false, 'can_manage_templates' => false,
            'can_view_costs' => false, 'can_administer' => false,
        ],
    ];

    /**
     * Base AI capability defaults for a role (before per-workspace overrides).
     *
     * @return array<string, bool>
     */
    public static function defaultsForRole(string $role): array
    {
        return self::ROLE_DEFAULTS[$role] ?? self::ROLE_DEFAULTS['viewer'];
    }

    public function resolve(Request $request): Project
    {
        $uuid = trim((string) ($request->input('workspace')
            ?? $request->query('workspace')
            ?? $request->header('X-Workspace-Id', '')));

        if ($uuid === '') {
            throw new HttpException(422, 'A workspace UUID is required.');
        }

        $project = Project::query()->where('uuid', $uuid)->first();

        if ($project === null) {
            throw new HttpException(404, 'Workspace not found.');
        }

        return $project;
    }

    /**
     * The caller's role within the workspace ("owner" when they own it).
     */
    public function roleFor(Project $project, User $user): string
    {
        if ($user->id === $project->owner_id) {
            return 'owner';
        }

        $membership = $project->memberships()->where('user_id', $user->id)->first();

        return $membership?->role ?? 'viewer';
    }

    /**
     * Effective AI capabilities for the caller: role defaults merged with per-workspace overrides.
     *
     * @return array<string, bool>
     */
    public function effectivePermissions(Project $project, User $user): array
    {
        $role = $this->roleFor($project, $user);
        $defaults = self::ROLE_DEFAULTS[$role] ?? self::ROLE_DEFAULTS['viewer'];

        $override = AiWorkspacePermission::query()
            ->where('project_id', $project->id)
            ->where('role', $role)
            ->first();

        if ($override === null) {
            return array_merge(['role' => $role], $defaults);
        }

        return [
            'role' => $role,
            'can_use_ai' => $override->can_use_ai,
            'can_manage_providers' => $override->can_manage_providers,
            'can_manage_templates' => $override->can_manage_templates,
            'can_view_costs' => $override->can_view_costs,
            'can_administer' => $override->can_administer,
        ];
    }
}
