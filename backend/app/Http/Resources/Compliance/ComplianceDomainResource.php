<?php

namespace App\Http\Resources\Compliance;

use App\Models\Compliance\ComplianceDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ComplianceDomain */
class ComplianceDomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'display_code' => $this->display_code,
            'normalized_code' => $this->normalized_code,
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'description_en' => $this->description_en,
            'description_ar' => $this->description_ar,
            'sort_order' => $this->sort_order,
            'status' => $this->status?->value,
            'source_reference' => $this->source_reference,
            'official_reference' => $this->official_reference,
            'source_page' => $this->source_page,
            'source_document' => ComplianceSourceDocumentResource::make($this->whenLoaded('sourceDocument')),
            'metadata' => $this->metadata,
        ];
    }
}
