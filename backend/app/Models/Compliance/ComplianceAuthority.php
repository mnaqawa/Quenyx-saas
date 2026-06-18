<?php

namespace App\Models\Compliance;

use App\Enums\Compliance\AuthorityStatus;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceAuthority extends Model
{
    use HasComplianceUuid;

    protected $table = 'compliance_authorities';

    protected $fillable = [
        'uuid',
        'key',
        'name_en',
        'name_ar',
        'short_name',
        'country_code',
        'website_url',
        'description_en',
        'description_ar',
        'status',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'status' => AuthorityStatus::class,
    ];

    public function frameworks(): HasMany
    {
        return $this->hasMany(ComplianceFramework::class, 'authority_id');
    }
}
