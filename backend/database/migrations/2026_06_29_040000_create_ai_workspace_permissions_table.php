<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 20 — Unified AI Workspace: per-workspace, per-role AI capability matrix.
 *
 * Augments (does NOT replace) the base ProjectPolicy RBAC. Stores, per workspace + role, which AI
 * capabilities are permitted (use AI, manage providers, manage templates, view costs, administer).
 * Absence of a row means "fall back to role defaults" — so the table is purely additive overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_workspace_permissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            // Role this rule applies to: owner | admin | member | viewer.
            $table->string('role', 32);
            $table->boolean('can_use_ai')->default(true);
            $table->boolean('can_manage_providers')->default(false);
            $table->boolean('can_manage_templates')->default(false);
            $table->boolean('can_view_costs')->default(false);
            $table->boolean('can_administer')->default(false);
            $table->timestamps();

            $table->unique(['project_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_workspace_permissions');
    }
};
