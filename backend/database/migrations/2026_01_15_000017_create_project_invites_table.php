<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->string('email');
            $table->string('role'); // 'admin', 'member', 'viewer'
            $table->foreignId('invited_by_user_id')->constrained('users')->onDelete('cascade');
            $table->string('status')->default('pending'); // 'pending', 'accepted', 'rejected', 'expired'
            $table->string('token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index('email');
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_invites');
    }
};
