<?php

namespace App\Providers;

use App\Services\AI\LlmClient;
use App\Services\Automation\ActionRegistry;
use App\Services\Automation\Adapters\PlannedExecutionAdapter;
use App\Services\Automation\Adapters\PowerShellExecutionAdapter;
use App\Services\Automation\Adapters\RestExecutionAdapter;
use App\Services\Automation\Adapters\ScriptExecutionAdapter;
use App\Services\Automation\Adapters\SshExecutionAdapter;
use App\Services\Automation\Adapters\WebhookExecutionAdapter;
use App\Services\Automation\AutomationAdapterRegistry;
use App\Services\QuenyxAI\Adapters\QynAssetAiAdapter;
use App\Services\QuenyxAI\Adapters\QynReactAiAdapter;
use App\Services\QuenyxAI\Adapters\QynRunAiAdapter;
use App\Services\QuenyxAI\Adapters\QynShieldAiAdapter;
use App\Services\QuenyxAI\Adapters\QynSightAiAdapter;
use App\Services\QuenyxAI\AiModuleAdapterRegistry;
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

        // Sprint 22 — the AI Adapter Registry is a process-wide singleton: modules register their
        // capability/action adapters here so Quenyx AI discovers them dynamically (no module branching).
        $this->app->singleton(AiModuleAdapterRegistry::class);

        // Sprint 23 — the Automation Platform registries are process-wide singletons. The Automation
        // Registry holds execution adapters; the Action Registry holds the reusable action catalog
        // (seeded from config, extensible at runtime). The Execution Engine resolves adapters ONLY
        // through the registry — there is no hardcoded execution path anywhere.
        $this->app->singleton(AutomationAdapterRegistry::class);
        $this->app->singleton(ActionRegistry::class, fn (): ActionRegistry => new ActionRegistry((array) config('automation.actions', [])));

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

        // Sprint 22 — register the module AI adapters with the AI Adapter Registry. Every future module
        // becomes AI-enabled by adding ONE line here (implement adapter + register) — nothing else in
        // the platform changes.
        $registry = $this->app->make(AiModuleAdapterRegistry::class);
        $registry->register($this->app->make(QynSightAiAdapter::class));
        $registry->register($this->app->make(QynAssetAiAdapter::class));
        // Sprint 23 — QynRun (automation) and QynReact (incident) become AI adapters the same way.
        $registry->register($this->app->make(QynRunAiAdapter::class));
        $registry->register($this->app->make(QynReactAiAdapter::class));

        // Sprint 23 — register execution adapters with the Automation Registry. Future runners plug in
        // here with one line (implement adapter + register) — the engine, registry, and APIs are unchanged.
        $automation = $this->app->make(AutomationAdapterRegistry::class);
        $automation->register($this->app->make(ScriptExecutionAdapter::class));
        $automation->register($this->app->make(RestExecutionAdapter::class));
        $automation->register($this->app->make(WebhookExecutionAdapter::class));
        $automation->register($this->app->make(SshExecutionAdapter::class));
        $automation->register($this->app->make(PowerShellExecutionAdapter::class));
        foreach ((array) config('automation.planned_adapters', []) as $planned) {
            $automation->register(new PlannedExecutionAdapter(
                (string) $planned['key'],
                (string) $planned['name'],
                (string) ($planned['category'] ?? 'generic'),
                (string) ($planned['description'] ?? ''),
            ));
        }
    }
}
