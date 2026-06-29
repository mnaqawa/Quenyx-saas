<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 20 — Unified AI Workspace: per-workspace provider preferences.
 *
 * Stores a workspace's chosen model and NON-secret preferences plus an ENCRYPTED settings blob for
 * any sensitive values (e.g. an org-supplied key override). The `settings` column is written via
 * Laravel's `encrypted` cast — secrets are never stored in plain text, and the API never returns
 * raw secret values (only a boolean "configured" indicator). Provider keys themselves come from the
 * config-driven AiProviderRegistry; this table only layers workspace-level preferences on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            // Provider key as known to the registry (e.g. "mock", "openai"). NOT a secret.
            $table->string('provider', 64);
            $table->boolean('enabled')->default(true);
            $table->string('model', 128)->nullable();
            // Encrypted at rest (Laravel 'encrypted' cast). Holds optional sensitive overrides.
            $table->text('settings')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_settings');
    }
};
