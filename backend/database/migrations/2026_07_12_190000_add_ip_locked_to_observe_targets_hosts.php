<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observe_targets_hosts')) {
            return;
        }

        Schema::table('observe_targets_hosts', function (Blueprint $table) {
            if (! Schema::hasColumn('observe_targets_hosts', 'ip_locked')) {
                $table->boolean('ip_locked')->default(false)->after('public_ip');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('observe_targets_hosts')) {
            return;
        }

        Schema::table('observe_targets_hosts', function (Blueprint $table) {
            if (Schema::hasColumn('observe_targets_hosts', 'ip_locked')) {
                $table->dropColumn('ip_locked');
            }
        });
    }
};
