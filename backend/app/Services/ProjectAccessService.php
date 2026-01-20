<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;

class ProjectAccessService
{
    /**
     * Check if user can manage project (owner or admin)
     */
    public function canManageProject(User $user, Project $project): bool
    {
        // Project owner can manage
        if ($project->owner_id === $user->id) {
            return true;
        }

        // Check if user is project admin
        $member = $project->memberships()
            ->where('user_id', $user->id)
            ->first();

        return $member && in_array($member->role, ['owner', 'admin'], true);
    }

    /**
     * Check if user can view project
     */
    public function canViewProject(User $user, Project $project): bool
    {
        // Project owner can view
        if ($project->owner_id === $user->id) {
            return true;
        }

        // Check if user is a member (any role)
        return $project->memberships()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Get user's role in project
     */
    public function getUserRole(User $user, Project $project): ?string
    {
        if ($project->owner_id === $user->id) {
            return 'owner';
        }

        $member = $project->memberships()
            ->where('user_id', $user->id)
            ->first();

        return $member?->role;
    }
}
