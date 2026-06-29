<?php

namespace App\Contracts\QuenyxAI;

use App\Models\Project;

/**
 * High-level capability/action contract for a Quenyx module's AI surface.
 *
 * This is the COARSE, discovery-oriented companion to {@see AiModuleAdapterInterface} (which is the
 * fine-grained, 3-stage context → reasoning → prompt pipeline). Where the pipeline interface answers
 * "how do I turn one request into a provider prompt", this interface answers "what intelligence does
 * this module expose, what contextual actions can a user take, and what deterministic operational
 * context grounds them".
 *
 * It exists so the platform (and UI) can DISCOVER a module's AI capabilities and contextual actions
 * without hard-coding them, and so a module can hand the shared Quenyx AI runtime a single, real,
 * workspace-scoped evidence context. Implementations REUSE the module's existing domain services and
 * never call an AI provider directly, never duplicate business logic, and never fabricate data.
 */
interface AiModuleAdapter
{
    /**
     * Stable module identifier (e.g. "qynsight").
     */
    public function moduleKey(): string;

    /**
     * The intelligence capabilities this module exposes (stable, machine-readable keys, e.g.
     * "monitoring_copilot", "root_cause_analysis").
     *
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * Build the deterministic, workspace-scoped AI context for this module from REAL domain data.
     * The shared Quenyx AI runtime narrates this context; it is never fabricated, and when evidence
     * is missing the context says so. No AI provider is called here.
     *
     * @param  array<string, mixed>  $options  Optional, adapter-specific tuning (e.g. time windows).
     * @return array<string, mixed>
     */
    public function buildContext(Project $project, array $options = []): array;

    /**
     * The contextual AI actions a user can invoke for this module (capability key, the entity it
     * targets, label, and the workspace-scoped, UUID-only endpoint that serves it). Used to render
     * contextual "✨ Quenyx AI" actions without hard-coding them in the UI.
     *
     * @return list<array<string, mixed>>
     */
    public function availableActions(): array;
}
