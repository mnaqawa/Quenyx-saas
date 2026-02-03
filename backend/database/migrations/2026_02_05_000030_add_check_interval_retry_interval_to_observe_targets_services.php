<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observe_targets_services', function (Blueprint $table) {
            $table->unsignedInteger('check_interval')->nullable()->after('enabled')->comment('Seconds between checks (Nagios check_interval)');
            $table->unsignedInteger('retry_interval')->nullable()->after('check_interval')->comment('Seconds before retry (Nagios retry_interval)');
        });
    }

    public function down(): void
    {
        Schema::table('observe_targets_services', function (Blueprint $table) {
            $table->dropColumn(['check_interval', 'retry_interval']);
        });
    }
};
