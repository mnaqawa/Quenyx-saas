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

        RateLimiter::for('compliance-ai-context-read', function (Request $request) {
            $max = (int) config('compliance.ai_context.rate_limits.read.max_attempts', 120);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('compliance-ai-context-search', function (Request $request) {
            $max = (int) config('compliance.ai_context.rate_limits.search.max_attempts', 30);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('compliance-graph-read', function (Request $request) {
            $max = (int) config('compliance.graph.rate_limits.read.max_attempts', 120);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('compliance-mapping-read', function (Request $request) {
            $max = (int) config('compliance.mappings.rate_limits.read.max_attempts', 120);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('ai-orchestration', function (Request $request) {
            $max = (int) config('ai.rate_limits.chat.max_attempts', 30);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('ai-skills', function (Request $request) {
            $max = (int) config('ai.rate_limits.skills.max_attempts', 60);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('compliance-evidence-read', function (Request $request) {
            $max = (int) config('compliance.evidence.rate_limits.read.max_attempts', 120);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('compliance-gap-read', function (Request $request) {
            $max = (int) config('compliance.gap.rate_limits.read.max_attempts', 120);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('compliance-copilot', function (Request $request) {
            $max = (int) config('compliance.copilot.rate_limits.message.max_attempts', 30);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('compliance-retrieval', function (Request $request) {
            $max = (int) config('compliance.retrieval.rate_limits.query.max_attempts', 60);

            return Limit::perMinute($max)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('compliance-recommendation-read', function (Request $request) {
            $max = (int) config('compliance.recommendations.rate_limits.read.max_attempts', 120);

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
