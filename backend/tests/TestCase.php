<?php

namespace Tests;

use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applyTestingRuntimeOverrides();
        $this->resetTestingAiConfig();
        $this->seedTestCatalog();
    }

    /**
     * Override cached production config so PHPUnit behaves deterministically.
     */
    protected function applyTestingRuntimeOverrides(): void
    {
        config([
            'app.env' => 'testing',
            'agent.require_gateway' => false,
            'ai.feature_flags.workspace_enabled' => true,
            'openai.vector_store_id' => 'vs_test123',
        ]);
    }

    /**
     * Prevent cached production config from leaking OpenAI keys into unit tests.
     */
    protected function resetTestingAiConfig(): void
    {
        config([
            'ai.default' => null,
            'ai.feature_flags.enabled' => null,
            'ai.providers.openai.api_key' => null,
            'openai.api_key' => null,
            'openai.models.performance_analyst' => null,
            'openai.models.anomaly_detector' => null,
            'openai.models.compliance' => null,
            'openai.models.capacity_planner' => null,
        ]);
    }

    /**
     * Seed plans/modules for any test using RefreshDatabase so entitlement gates resolve.
     */
    protected function seedTestCatalog(): void
    {
        if (! in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            return;
        }

        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);
    }

    protected function subscribeProject(Project $project, string $planKey = 'enterprise'): ProjectSubscription
    {
        $plan = Plan::where('key', $planKey)->firstOrFail();

        return ProjectSubscription::updateOrCreate(
            ['project_id' => $project->id],
            [
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
            ]
        );
    }
}
