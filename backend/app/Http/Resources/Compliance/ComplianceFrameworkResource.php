<?php

namespace App\Http\Resources\Compliance;

use App\Models\Compliance\ComplianceFramework;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ComplianceFramework */
class ComplianceFrameworkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'key' => $this->key,
            'code' => $this->code,
            'slug' => $this->slug,
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'description_en' => $this->description_en,
            'description_ar' => $this->description_ar,
            'status' => $this->status?->value,
            'authority' => $this->whenLoaded('authority', fn () => [
                'uuid' => $this->authority?->uuid,
                'key' => $this->authority?->key,
                'short_name' => $this->authority?->short_name,
                'name_en' => $this->authority?->name_en,
                'name_ar' => $this->authority?->name_ar,
            ]),
        ];
    }
}
