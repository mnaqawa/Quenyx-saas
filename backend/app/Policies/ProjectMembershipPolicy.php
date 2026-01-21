<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;

class ProjectMembershipPolicy
{
    /**
     * Determine if user can view memberships and invites
     */
    public function viewAny(User $user, Project $project): bool
    {
        // Owner can view
        if ($project->owner_id === $user->id) {
            return true;
        }

        // Admin can view
        $membership = $project->memberships()
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->first();

        return $membership !== null;
    }

    /**
     * Determine if user can create membership (invite)
     */
    public function create(User $user, Project $project): bool
    {
        return $this->viewAny($user, $project);
    }

    /**
     * Determine if user can update membership (change role)
     */
    public function update(User $user, ProjectMembership $membership): bool
    {
        $project = $membership->project;
        
        // Must be owner or admin
        if ($project->owner_id !== $user->id) {
            $userMembership = $project->memberships()
                ->where('user_id', $user->id)
                ->where('role', 'admin')
                ->first();

            if (!$userMembership) {
                return false;
            }

            // Admin cannot change/remove owner
            if ($membership->role === 'owner') {
                return false;
            }
        }

        // Cannot demote last owner
        if ($membership->role === 'owner') {
            $ownerCount = $project->memberships()
                ->where('role', 'owner')
                ->count();

            if ($ownerCount <= 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if user can delete membership (remove member)
     */
    public function delete(User $user, ProjectMembership $membership): bool
    {
        // Get project from membership if not provided
        $project = $project ?? $membership->project;
        
        // Must be owner or admin
        if ($project->owner_id !== $user->id) {
            $userMembership = $project->memberships()
                ->where('user_id', $user->id)
                ->where('role', 'admin')
                ->first();

            if (!$userMembership) {
                return false;
            }

            // Admin cannot remove owner
            if ($membership->role === 'owner') {
                return false;
            }
        }

        // Cannot remove last owner
        if ($membership->role === 'owner') {
            $ownerCount = $project->memberships()
                ->where('role', 'owner')
                ->count();

            if ($ownerCount <= 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if user can promote to owner
     */
    public function promoteToOwner(User $user, Project $project): bool
    {
        // Only owner can promote to owner
        return $project->owner_id === $user->id;
    }
}
