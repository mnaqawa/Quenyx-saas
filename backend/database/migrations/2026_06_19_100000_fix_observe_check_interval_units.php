<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UI collected check/retry intervals in minutes but the engine stores seconds.
 * Values under 60 were saved as "5 min" → 5 seconds, causing extreme check churn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observe_target_services')) {
            return;
        }

        DB::table('observe_target_services')
            ->whereNotNull('check_interval')
            ->where('check_interval', '>', 0)
            ->where('check_interval', '<', 60)
            ->update([
                'check_interval' => DB::raw('check_interval * 60'),
            ]);

        if (Schema::hasColumn('observe_target_services', 'retry_interval')) {
            DB::table('observe_target_services')
                ->whereNotNull('retry_interval')
                ->where('retry_interval', '>', 0)
                ->where('retry_interval', '<', 60)
                ->update([
                    'retry_interval' => DB::raw('retry_interval * 60'),
                ]);
        }
    }

    public function down(): void
    {
        //
    }
};
