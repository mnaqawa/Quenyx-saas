<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 20 — Unified AI Workspace: reusable prompt templates per workspace.
 *
 * Workspace-scoped, UUID-addressed, fully audited (created_by / updated_by). Content is
 * user-authored prompt scaffolding only — NO tenant evidence or compliance corpus is stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->string('category', 64)->nullable();
            $table->longText('body');
            // Optional declared variables (e.g. ["framework","control_code"]) for UI hinting.
            $table->json('variables')->nullable();
            $table->boolean('is_shared')->default(true);
            $table->timestamps();

            $table->index(['project_id', 'category']);
            $table->index(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_templates');
    }
};
