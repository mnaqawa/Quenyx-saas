<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentInventory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

        $agent->markSeen();
    }
}
