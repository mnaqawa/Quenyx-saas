<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectMembershipPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Project::class => ProjectPolicy::class,
        ProjectMembership::class => ProjectMembershipPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
