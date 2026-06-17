<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observe_alert_events')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Expand enum to include 'open' before migrating legacy 'active' rows.
        DB::statement(
            "ALTER TABLE observe_alert_events MODIFY COLUMN status ENUM('open', 'active', 'acknowledged', 'resolved') NOT NULL DEFAULT 'open'"
        );

        DB::table('observe_alert_events')->where('status', 'active')->update(['status' => 'open']);

        DB::statement(
            "ALTER TABLE observe_alert_events MODIFY COLUMN status ENUM('open', 'acknowledged', 'resolved') NOT NULL DEFAULT 'open'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('observe_alert_events')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE observe_alert_events MODIFY COLUMN status ENUM('open', 'active', 'acknowledged', 'resolved') NOT NULL DEFAULT 'active'"
        );

        DB::table('observe_alert_events')->where('status', 'open')->update(['status' => 'active']);

        DB::statement(
            "ALTER TABLE observe_alert_events MODIFY COLUMN status ENUM('active', 'acknowledged', 'resolved') NOT NULL DEFAULT 'active'"
        );
    }
};
