<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('observe_targets_hosts')) {
            Schema::table('observe_targets_hosts', function (Blueprint $table) {
                if (! Schema::hasColumn('observe_targets_hosts', 'uuid')) {
                    $table->uuid('uuid')->nullable()->after('id');
                }
                if (! Schema::hasColumn('observe_targets_hosts', 'lifecycle_status')) {
                    $table->string('lifecycle_status', 32)->default('active')->after('enabled');
                }
                if (! Schema::hasColumn('observe_targets_hosts', 'lifecycle_reason')) {
                    $table->string('lifecycle_reason', 500)->nullable()->after('lifecycle_status');
                }
                if (! Schema::hasColumn('observe_targets_hosts', 'lifecycle_changed_at')) {
                    $table->timestamp('lifecycle_changed_at')->nullable()->after('lifecycle_reason');
                }
                if (! Schema::hasColumn('observe_targets_hosts', 'deleted_at')) {
                    $table->softDeletes();
                }
            });

            DB::table('observe_targets_hosts')
                ->whereNull('uuid')
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('observe_targets_hosts')
                            ->where('id', $row->id)
                            ->update(['uuid' => (string) Str::uuid()]);
                    }
                });
        }

        if (Schema::hasTable('agents')) {
            Schema::table('agents', function (Blueprint $table) {
                if (! Schema::hasColumn('agents', 'revoked_at')) {
                    $table->timestamp('revoked_at')->nullable()->after('status');
                }
                if (! Schema::hasColumn('agents', 'revoked_reason')) {
                    $table->string('revoked_reason', 500)->nullable()->after('revoked_at');
                }
                if (! Schema::hasColumn('agents', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('observe_targets_hosts')) {
            Schema::table('observe_targets_hosts', function (Blueprint $table) {
                foreach (['uuid', 'lifecycle_status', 'lifecycle_reason', 'lifecycle_changed_at', 'deleted_at'] as $col) {
                    if (Schema::hasColumn('observe_targets_hosts', $col)) {
                        if ($col === 'deleted_at') {
                            $table->dropSoftDeletes();
                        } else {
                            $table->dropColumn($col);
                        }
                    }
                }
            });
        }

        if (Schema::hasTable('agents')) {
            Schema::table('agents', function (Blueprint $table) {
                foreach (['revoked_at', 'revoked_reason', 'deleted_at'] as $col) {
                    if (Schema::hasColumn('agents', $col)) {
                        if ($col === 'deleted_at') {
                            $table->dropSoftDeletes();
                        } else {
                            $table->dropColumn($col);
                        }
                    }
                }
            });
        }
    }
};
