<?php

namespace App\Providers;

use App\Services\AI\LlmClient;
use App\Services\QuenyxAI\Adapters\QynShieldAiAdapter;
use App\Services\QuenyxAI\QuenyxAiPlatform;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\ServiceProvider;
use OpenAI;
use OpenAI\Contracts\ClientContract;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // QCIF Sprint 19 — the Quenyx AI Platform is a process-wide singleton so registered module
        // adapters persist for the request lifecycle.
        $this->app->singleton(QuenyxAiPlatform::class);

        // LlmClient is config-driven; bind it so AiAgentService can be auto-resolved.
        $this->app->singleton(LlmClient::class, fn () => LlmClient::fromConfig());

        $this->app->singleton(ClientContract::class, function (): ClientContract {
            $apiKey = (string) config('openai.api_key');
            $factory = OpenAI::factory()
                ->withApiKey($apiKey)
                ->withHttpClient(new GuzzleClient([
                    'timeout' => (int) config('openai.request_timeout', 60),
                ]));

            $organization = (string) config('openai.organization');
            if ($organization !== '') {
                $factory = $factory->withOrganization($organization);
            }

            return $factory->make();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // QCIF Sprint 19 — register QynShield as the first Quenyx AI Platform adapter. Future modules
        // (QynSight, …) register the same way with no platform change.
        $this->app->make(QuenyxAiPlatform::class)
            ->registerAdapter($this->app->make(QynShieldAiAdapter::class));
    }
}
