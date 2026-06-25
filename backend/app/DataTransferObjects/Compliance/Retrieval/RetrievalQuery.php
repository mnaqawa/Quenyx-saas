<?php

namespace App\DataTransferObjects\Compliance\Retrieval;

use App\Enums\Compliance\Retrieval\ComplianceRetrievalMode;

/**
 * A deterministic retrieval request (QCIF Sprint 15). Pure data — no AI, no DB.
 */
final readonly class RetrievalQuery
{
    public function __construct(
        public string $query,
        public ComplianceRetrievalMode $mode,
        public int $projectId,
        public ?string $framework = null,
        public ?string $release = null,
        public int $limit = 20,
        public ?string $code = null,
    ) {}

    public function withCode(?string $code): self
    {
        return new self($this->query, $this->mode, $this->projectId, $this->framework, $this->release, $this->limit, $code);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'mode' => $this->mode->value,
            'framework' => $this->framework,
            'release' => $this->release,
            'limit' => $this->limit,
            'code' => $this->code,
        ];
    }
}
