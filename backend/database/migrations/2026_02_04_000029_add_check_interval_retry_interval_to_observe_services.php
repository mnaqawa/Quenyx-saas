<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observe_services', function (Blueprint $table) {
            $table->unsignedInteger('check_interval')->nullable()->after('last_state_change_at');
            $table->unsignedInteger('retry_interval')->nullable()->after('check_interval');
        });
    }

    public function down(): void
    {
        Schema::table('observe_services', function (Blueprint $table) {
            $table->dropColumn(['check_interval', 'retry_interval']);
        });
    }
};
