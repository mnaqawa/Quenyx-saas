<?php

namespace App\DataTransferObjects\Ai;

/**
 * Execution trace for a single skill run: a UUID, status, wall-clock duration, and timestamps.
 */
final readonly class AiSkillExecution
{
    public function __construct(
        public string $uuid,
        public string $skillKey,
        public string $status,
        public float $durationMs,
        public string $startedAt,
        public string $finishedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'skill' => $this->skillKey,
            'status' => $this->status,
            'duration_ms' => round($this->durationMs, 3),
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];
    }
}
