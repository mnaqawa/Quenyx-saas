<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_enrollment_tokens', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('workspace_id')->constrained('users')->nullOnDelete();
            $table->string('allowed_hostname')->nullable()->after('name');
            $table->string('target_os', 32)->nullable()->after('allowed_hostname');
            $table->timestamp('revoked_at')->nullable()->after('used_at');
            $table->timestamp('last_used_at')->nullable()->after('revoked_at');
            $table->string('status', 20)->default('active')->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_enrollment_tokens', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['created_by', 'allowed_hostname', 'target_os', 'revoked_at', 'last_used_at', 'status']);
        });
    }
};
