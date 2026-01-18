<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'price_cents',
        'interval',
        'features',
    ];

    protected $casts = [
        'features' => 'array',
        'price_cents' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ProjectSubscription::class);
    }
}
