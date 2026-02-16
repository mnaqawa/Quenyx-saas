<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_enrollment_tokens', function (Blueprint $table) {
            $table->string('primary_protocol', 32)->default('http_api')->after('expires_at');
            $table->json('enabled_protocols')->nullable()->after('primary_protocol');
            $table->json('permissions')->nullable()->after('enabled_protocols');
        });
    }

    public function down(): void
    {
        Schema::table('agent_enrollment_tokens', function (Blueprint $table) {
            $table->dropColumn(['primary_protocol', 'enabled_protocols', 'permissions']);
        });
    }
};
