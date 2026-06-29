<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sprint 20 — Unified AI Workspace.
 *
 * Adds a stable, public UUID to projects so platform-level APIs (e.g. the Unified AI Workspace,
 * which is NOT nested under /workspaces/{project}) can scope by a non-enumerable identifier while
 * preserving UUID-only API contracts. This is fully backward compatible: the existing numeric
 * `id` and all existing `{project}` route bindings are untouched; the column is nullable, then
 * backfilled for existing rows, then made unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'uuid')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->after('id');
            });
        }

        // Backfill existing rows deterministically (one UUID per project). Collect ids first so the
        // updates don't interfere with chunked iteration over the same filtered column.
        $ids = DB::table('projects')->whereNull('uuid')->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            DB::table('projects')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
