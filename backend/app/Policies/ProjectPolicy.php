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
        // User is owner
        if ($user->id === $project->owner_id) {
            return true;
        }
        
        // User is a member
        return $project->memberships()->where('user_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Project $project): bool
    {
        return $user->id === $project->owner_id;
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->id === $project->owner_id;
    }
}
