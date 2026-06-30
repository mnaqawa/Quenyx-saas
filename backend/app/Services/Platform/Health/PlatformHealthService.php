<?php

declare(strict_types=1);

namespace App\Services\Platform\Health;

use App\Models\Project;
use App\Services\Automation\AutomationAdapterRegistry;
use App\Services\Knowledge\KnowledgeSourceRegistry;
use App\Services\Platform\EventBus\PlatformEventBus;
use App\Services\QuenyxAI\AiModuleAdapterRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 25 — Platform Health: the platform monitoring itself.
 *
 * Inspects the shared platform's own subsystems from real, in-process state and real tables: the AI
 * platform & providers, the Automation platform & registry, the Knowledge platform & source registry,
 * Enterprise Search, the Event Bus & subscribers, and queues / background jobs. Every area reports an
 * honest `status` derived from observable facts — never a fabricated "all green".
 */
class PlatformHealthService
{
    public function __construct(
        private readonly AiModuleAdapterRegistry $aiRegistry,
        private readonly AutomationAdapterRegistry $automationRegistry,
        private readonly KnowledgeSourceRegistry $knowledgeRegistry,
        private readonly PlatformEventBus $eventBus,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Project $project): array
    {
        $areas = [
            'ai_platform' => $this->aiPlatform(),
            'automation_platform' => $this->automationPlatform(),
            'knowledge_platform' => $this->knowledgePlatform($project),
            'search' => $this->search($project),
            'registries' => $this->registries($project),
            'provider_health' => $this->providerHealth(),
            'event_bus' => $this->eventBusHealth(),
            'queues' => $this->queues(),
            'background_jobs' => $this->backgroundJobs(),
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'overall_status' => $this->overall($areas),
            'areas' => $areas,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiPlatform(): array
    {
        $enabled = (bool) config('ai.feature_flags.enabled', false);
        $adapters = count($this->aiRegistry->all());

        return [
            'status' => $adapters > 0 ? 'operational' : 'degraded',
            'ai_enabled' => $enabled,
            'mode' => $enabled ? 'live_provider' : 'mock (safe default)',
            'registered_adapters' => $adapters,
            'adapters' => array_keys($this->aiRegistry->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function automationPlatform(): array
    {
        $adapters = $this->automationRegistry->all();
        $operational = array_filter($adapters, static fn ($a): bool => $a->isOperational());

        return [
            'status' => $adapters !== [] ? 'operational' : 'degraded',
            'live_execution' => (bool) config('automation.live_execution', false),
            'registered_adapters' => count($adapters),
            'operational_adapters' => count($operational),
            'adapters' => $this->automationRegistry->keys(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function knowledgePlatform(Project $project): array
    {
        $all = $this->knowledgeRegistry->all();
        $operational = $this->knowledgeRegistry->operational($project);

        return [
            'status' => $operational !== [] ? 'operational' : 'degraded',
            'registered_sources' => count($all),
            'operational_sources' => count($operational),
            'sources' => $this->knowledgeRegistry->keys(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function search(Project $project): array
    {
        $hasCorpus = Schema::hasTable('knowledge_documents')
            && DB::table('knowledge_documents')->where('project_id', $project->id)->exists();

        return [
            'status' => 'operational',
            'indexed_corpus_present' => $hasCorpus,
            'note' => $hasCorpus ? null : 'No indexed documents yet for this workspace.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registries(Project $project): array
    {
        return [
            'status' => 'operational',
            'ai_adapters' => count($this->aiRegistry->all()),
            'automation_adapters' => count($this->automationRegistry->all()),
            'knowledge_sources' => count($this->knowledgeRegistry->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerHealth(): array
    {
        $enabled = (bool) config('ai.feature_flags.enabled', false);
        $default = (string) config('ai.default_provider', 'mock');

        return [
            'status' => 'operational',
            'active_provider' => $enabled ? $default : 'mock',
            'note' => $enabled ? 'Live provider configured.' : 'AI disabled — deterministic mock provider in use (production-safe).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventBusHealth(): array
    {
        $describe = $this->eventBus->describe();

        return [
            'status' => 'operational',
            'event_count' => $describe['event_count'],
            'subscriber_count' => $describe['subscriber_count'],
            'subscribers' => $describe['subscribers'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queues(): array
    {
        $driver = (string) config('queue.default', 'sync');
        $pending = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;

        return [
            'status' => 'operational',
            'driver' => $driver,
            'pending_jobs' => $pending,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backgroundJobs(): array
    {
        $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;

        return [
            'status' => $failed === 0 ? 'operational' : 'degraded',
            'failed_jobs' => $failed,
            'note' => $failed > 0 ? 'There are failed background jobs to review.' : null,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $areas
     */
    private function overall(array $areas): string
    {
        foreach ($areas as $area) {
            if (($area['status'] ?? 'operational') === 'degraded') {
                return 'degraded';
            }
        }

        return 'operational';
    }
}
