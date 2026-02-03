<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observe_targets_services', function (Blueprint $table) {
            $table->string('service_key', 64)->nullable()->after('name');
            $table->index(['workspace_id', 'service_key']);
        });
    }

    public function down(): void
    {
        Schema::table('observe_targets_services', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'service_key']);
            $table->dropColumn('service_key');
        });
    }
};
