<?php

namespace App\Models\Compliance\Concerns;

use Illuminate\Support\Str;

trait HasComplianceUuid
{
    protected static function bootHasComplianceUuid(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
