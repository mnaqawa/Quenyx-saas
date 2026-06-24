<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QCIF Sprint 9 — AI Orchestration Platform session model.
 *
 * Stores conversation/session metadata, usage, and token counts ONLY. Prompt/message CONTENT
 * is nullable and is written exclusively when ai.feature_flags.prompt_logging is enabled — no
 * tenant knowledge is stored by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 64);
            $table->string('model', 128)->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedBigInteger('prompt_tokens')->default(0);
            $table->unsignedBigInteger('completion_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->json('usage_metadata')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'provider']);
            $table->index('status');
        });

        Schema::create('ai_conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 32);
            // Content is null unless prompt logging is explicitly enabled.
            $table->longText('content')->nullable();
            $table->unsignedBigInteger('prompt_tokens')->default(0);
            $table->unsignedBigInteger('completion_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->boolean('mocked')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ai_conversation_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
