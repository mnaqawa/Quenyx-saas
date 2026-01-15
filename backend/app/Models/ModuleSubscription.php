<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'module_id',
        'subscription_state',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
