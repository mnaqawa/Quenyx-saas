<?php

namespace App\Http\Resources\Compliance;

use App\Models\Compliance\ComplianceCorpusRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ComplianceCorpusRevision */
class ComplianceCorpusRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'revision_number' => $this->revision_number,
            'status' => $this->status?->value,
            'checksum_sha256' => $this->checksum_sha256,
            'entity_counts' => $this->entity_counts,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'import_run_uuid' => $this->whenLoaded('importRun', fn () => $this->importRun?->uuid),
            'metadata' => $this->metadata,
        ];
    }
}
