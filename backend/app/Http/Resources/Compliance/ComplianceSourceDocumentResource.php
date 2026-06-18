<?php

namespace App\Http\Resources\Compliance;

use App\Models\Compliance\ComplianceSourceDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ComplianceSourceDocument */
class ComplianceSourceDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'key' => $this->key,
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'document_type' => $this->document_type?->value,
            'language' => $this->language?->value,
            'source_url' => $this->source_url,
            'official_file_name' => $this->official_file_name,
            'checksum_sha256' => $this->checksum_sha256,
            'source_reference' => $this->source_reference,
            'publication_date' => $this->publication_date?->toDateString(),
            'effective_date' => $this->effective_date?->toDateString(),
            'status' => $this->status?->value,
        ];
    }
}
