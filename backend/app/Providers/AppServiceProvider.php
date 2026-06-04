<?php

namespace App\Providers;

use App\Services\AI\LlmClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // LlmClient is config-driven; bind it so AiAgentService can be auto-resolved.
        $this->app->singleton(LlmClient::class, fn () => LlmClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
