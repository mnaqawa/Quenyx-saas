<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_configurations', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->foreignId('integration_id')->nullable()->constrained('integrations')->onDelete('cascade');
            $table->json('settings')->nullable();

            $table->index('project_id');
            $table->index('integration_id');
        });
    }

    public function down(): void
    {
        Schema::table('integration_configurations', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['integration_id']);
            $table->dropIndex(['project_id']);
            $table->dropIndex(['integration_id']);
            $table->dropColumn(['project_id', 'integration_id', 'settings']);
        });
    }
};
