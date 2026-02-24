<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observe_targets_hosts', function (Blueprint $table) {
            $table->string('public_ip', 45)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('observe_targets_hosts', function (Blueprint $table) {
            $table->dropColumn('public_ip');
        });
    }
};
