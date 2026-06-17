<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        // Allow authenticated users to view their projects list
        // The controller filters by owner_id, so this is safe
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        // Owner can always view
        if ($user->id === $project->owner_id) {
            return true;
        }
        
        // Any member (owner, admin, member, viewer) can view observe data
        // This allows read-only access to observe endpoints for all roles
        return $project->memberships()->where('user_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Project $project): bool
    {
        // Owner can always update
        if ($user->id === $project->owner_id) {
            return true;
        }
        
        // Only owner and admin members can update (edit targets, etc.)
        // Member and viewer roles are read-only
        $membership = $project->memberships()->where('user_id', $user->id)->first();
        return $membership && in_array($membership->role, ['owner', 'admin']);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->id === $project->owner_id;
    }

    /**
     * Acknowledge alerts: owner, admin, and member roles. Viewers are read-only.
     */
    public function acknowledgeAlert(User $user, Project $project): bool
    {
        if ($user->id === $project->owner_id) {
            return true;
        }

        $membership = $project->memberships()->where('user_id', $user->id)->first();

        return $membership && in_array($membership->role, ['owner', 'admin', 'member']);
    }

    /**
     * Run operational observe actions (recheck all, port scans). Owner, admin, member only.
     */
    public function runObserveOperations(User $user, Project $project): bool
    {
        if ($user->id === $project->owner_id) {
            return true;
        }

        $membership = $project->memberships()->where('user_id', $user->id)->first();

        return $membership && in_array($membership->role, ['owner', 'admin', 'member']);
    }
}
