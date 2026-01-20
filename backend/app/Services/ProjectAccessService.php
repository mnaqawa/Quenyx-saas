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
        // System admin can manage any project
        if ($user->isSystemAdmin()) {
            return true;
        }

        // Project owner can manage
        if ($project->owner_id === $user->id) {
            return true;
        }

        // Check if user is project admin
        $member = $project->members()
            ->where('user_id', $user->id)
            ->first();

        return $member && in_array($member->role, ['owner', 'admin'], true);
    }

    /**
     * Check if user can view project
     */
    public function canViewProject(User $user, Project $project): bool
    {
        // System admin can view any project
        if ($user->isSystemAdmin()) {
            return true;
        }

        // Project owner can view
        if ($project->owner_id === $user->id) {
            return true;
        }

        // Check if user is a member (any role)
        return $project->members()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Get user's role in project
     */
    public function getUserRole(User $user, Project $project): ?string
    {
        if ($user->isSystemAdmin()) {
            return 'system_admin';
        }

        if ($project->owner_id === $user->id) {
            return 'owner';
        }

        $member = $project->members()
            ->where('user_id', $user->id)
            ->first();

        return $member?->role;
    }
}
