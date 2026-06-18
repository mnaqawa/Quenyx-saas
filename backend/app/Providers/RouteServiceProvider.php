<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
            RateLimiter::for('api', function (Request $request) {
            // No auth yet; throttle by IP only
            return Limit::perMinute(120)->by($request->ip());
        });

        RateLimiter::for('compliance-corpus-read', function (Request $request) {
            $max = (int) config('compliance.corpus.rate_limits.read.max_attempts', 120);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('compliance-corpus-search', function (Request $request) {
            $max = (int) config('compliance.corpus.rate_limits.search.max_attempts', 30);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
