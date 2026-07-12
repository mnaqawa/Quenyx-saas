<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentInventory;
use App\Models\ObserveTargetHost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AgentIngestInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public string $agentId,
        public string $collectedAt,
        public array $payload
    ) {}

    public function handle(): void
    {
        $agent = Agent::find($this->agentId);
        if (! $agent) {
            Log::warning('AgentIngestInventoryJob: agent not found', ['agent_id' => $this->agentId]);

            return;
        }

        AgentInventory::updateOrCreate(
            [
                'agent_id' => $this->agentId,
                'collected_at' => $this->collectedAt,
            ],
            ['payload' => $this->payload]
        );

        $agentUpdates = [];
        if (! empty($this->payload['os']) && is_string($this->payload['os'])) {
            $agentUpdates['os'] = $this->payload['os'];
        }
        if (! empty($this->payload['arch']) && is_string($this->payload['arch'])) {
            $agentUpdates['arch'] = $this->payload['arch'];
        }
        if (! empty($this->payload['hostname']) && is_string($this->payload['hostname'])) {
            $agentUpdates['hostname'] = $this->payload['hostname'];
        }
        if ($agentUpdates !== []) {
            $agent->forceFill($agentUpdates)->save();
        }

        $this->syncHostOsTags($agent);

        $agent->markSeen();
    }

    private function syncHostOsTags(Agent $agent): void
    {
        if (! Schema::hasColumn('observe_targets_hosts', 'tags')) {
            return;
        }

        $os = isset($this->payload['os']) && is_string($this->payload['os'])
            ? strtolower(trim($this->payload['os']))
            : '';
        if ($os === '') {
            return;
        }

        $osTag = 'os:'.$os;

        ObserveTargetHost::query()
            ->where('agent_id', $agent->id)
            ->get()
            ->each(function (ObserveTargetHost $host) use ($osTag, $os) {
                $tags = is_array($host->tags) ? array_values($host->tags) : [];
                $filtered = array_values(array_filter(
                    $tags,
                    fn ($t) => ! is_string($t) || (! str_starts_with(strtolower($t), 'os:') && strtolower($t) !== $os)
                ));
                $filtered[] = $osTag;
                $host->forceFill(['tags' => array_values(array_unique($filtered))])->save();
            });
    }
}
