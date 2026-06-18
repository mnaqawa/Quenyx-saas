<?php

namespace App\Http\Resources\Compliance;

use App\Models\Compliance\ComplianceFrameworkRelease;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ComplianceFrameworkRelease */
class ComplianceFrameworkReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'release_code' => $this->release_code,
            'version_code' => $this->version_code,
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'description_en' => $this->description_en,
            'description_ar' => $this->description_ar,
            'effective_date' => $this->effective_date?->toDateString(),
            'published_at' => $this->published_at?->toIso8601String(),
            'status' => $this->status?->value,
            'stable_ref' => $this->stableRef(),
            'framework' => ComplianceFrameworkResource::make($this->whenLoaded('framework')),
        ];
    }
}
