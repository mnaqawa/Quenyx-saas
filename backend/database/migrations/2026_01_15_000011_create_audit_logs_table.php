<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->string('action'); // e.g. 'module_override_updated'
            $table->json('metadata'); // { module_key, old_mode, new_mode, allowed_by_plan, ... }
            $table->timestamp('timestamp')->useCurrent();
            $table->timestamps();

            $table->index('project_id');
            $table->index('user_id');
            $table->index('action');
            $table->index('timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
