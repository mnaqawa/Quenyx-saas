<?php

namespace App\Models\Compliance\Gap\Concerns;

use RuntimeException;

/**
 * Enforces append-only (immutable) semantics for gap assessment history: once a record is
 * created it can never be updated or deleted via Eloquent. This guarantees reproducibility — a
 * stored assessment always reflects the exact inputs at the time it was run.
 */
trait ImmutableModel
{
    protected static function bootImmutableModel(): void
    {
        static::updating(function ($model): void {
            throw new RuntimeException(static::class.' is immutable and cannot be updated.');
        });

        static::deleting(function ($model): void {
            throw new RuntimeException(static::class.' is immutable and cannot be deleted.');
        });
    }
}
