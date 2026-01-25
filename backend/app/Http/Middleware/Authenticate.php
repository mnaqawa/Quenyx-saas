<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     * 
     * For API-only applications, always return null to trigger JSON 401 response
     * instead of redirecting to a login route.
     */
    protected function redirectTo(Request $request): ?string
    {
        // API-only application - always return JSON 401, never redirect
        return null;
    }
}
