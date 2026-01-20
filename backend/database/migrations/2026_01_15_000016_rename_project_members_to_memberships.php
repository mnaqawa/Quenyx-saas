<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rename table if it exists
        if (Schema::hasTable('project_members')) {
            Schema::rename('project_members', 'project_memberships');
        } else {
            // Create new table if old doesn't exist
            Schema::create('project_memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->string('role'); // 'owner', 'admin', 'member', 'viewer'
                $table->timestamps();

                $table->unique(['project_id', 'user_id']);
                $table->index('project_id');
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_memberships')) {
            Schema::rename('project_memberships', 'project_members');
        }
    }
};
