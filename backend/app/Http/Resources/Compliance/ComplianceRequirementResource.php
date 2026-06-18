<?php

namespace App\Http\Resources\Compliance;

use App\Models\Compliance\ComplianceRequirement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ComplianceRequirement */
class ComplianceRequirementResource extends JsonResource
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
            'requirement_text_en' => $this->requirement_text_en,
            'requirement_text_ar' => $this->requirement_text_ar,
            'sort_order' => $this->sort_order,
            'status' => $this->status?->value,
            'source_reference' => $this->source_reference,
            'official_reference' => $this->official_reference,
            'source_page' => $this->source_page,
            'source_document' => ComplianceSourceDocumentResource::make($this->whenLoaded('sourceDocument')),
            'control' => ComplianceControlResource::make($this->whenLoaded('control')),
            'guidance_items' => $this->whenLoaded('guidanceItems', fn () => $this->guidanceItems->map(fn ($item) => [
                'uuid' => $item->uuid,
                'code' => $item->code,
                'guidance_en' => $item->guidance_en,
                'guidance_ar' => $item->guidance_ar,
                'guidance_type' => $item->guidance_type?->value,
                'source_reference' => $item->source_reference,
                'official_reference' => $item->official_reference,
                'source_page' => $item->source_page,
                'source_document' => ComplianceSourceDocumentResource::make($item->relationLoaded('sourceDocument') ? $item->sourceDocument : null),
            ])),
            'evidence_expectations' => $this->whenLoaded('evidenceExpectations', fn () => $this->evidenceExpectations->map(fn ($item) => [
                'uuid' => $item->uuid,
                'code' => $item->code,
                'title_en' => $item->title_en,
                'title_ar' => $item->title_ar,
                'description_en' => $item->description_en,
                'description_ar' => $item->description_ar,
                'is_required' => $item->is_required,
                'recency_days' => $item->recency_days,
                'source_reference' => $item->source_reference,
                'official_reference' => $item->official_reference,
                'source_page' => $item->source_page,
                'evidence_type' => $item->relationLoaded('evidenceType') && $item->evidenceType ? [
                    'uuid' => $item->evidenceType->uuid,
                    'key' => $item->evidenceType->key,
                    'title_en' => $item->evidenceType->title_en,
                    'title_ar' => $item->evidenceType->title_ar,
                ] : null,
                'source_document' => ComplianceSourceDocumentResource::make($item->relationLoaded('sourceDocument') ? $item->sourceDocument : null),
            ])),
            'metadata' => $this->metadata,
        ];
    }
}
