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
 * Sprint 22 generalised this into the foundation of the AI Adapter Platform: every module (QynSight,
 * QynAsset, and every future module) implements this contract and registers with the
 * {@see \App\Services\QuenyxAI\AiModuleAdapterRegistry}. Quenyx AI then DISCOVERS modules,
 * capabilities, actions, entities, skills and providers dynamically — there is no per-module
 * branching anywhere in the platform.
 *
 * Implementations REUSE the module's existing domain services and the shared Quenyx AI runtime; they
 * never call an AI provider directly, never duplicate business logic, and never fabricate data.
 *
 * The metadata methods (moduleName/Description/Category/Version/Icon, supportedEntities/Skills/
 * Providers) were added in Sprint 22 in a BACKWARD-COMPATIBLE way: {@see AbstractAiModuleAdapter}
 * supplies sensible defaults so existing adapters keep working and new adapters override only what
 * they need.
 */
interface AiModuleAdapter
{
    /**
     * Stable module identifier (e.g. "qynsight", "qynasset").
     */
    public function moduleKey(): string;

    /**
     * Human-readable module name (e.g. "QynSight").
     */
    public function moduleName(): string;

    /**
     * Short description of the module's AI surface.
     */
    public function moduleDescription(): string;

    /**
     * Functional category (e.g. "Operations", "Asset Management").
     */
    public function moduleCategory(): string;

    /**
     * Adapter version (semantic, e.g. "1.0.0").
     */
    public function moduleVersion(): string;

    /**
     * UI icon key for the module (renderer-agnostic).
     */
    public function moduleIcon(): string;

    /**
     * The intelligence capabilities this module exposes (stable, machine-readable keys, e.g.
     * "monitoring_copilot", "asset_discovery_intelligence").
     *
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * The entity types this module's actions operate on (e.g. "host", "service", "asset",
     * "dependency"). Used to route contextual actions to the right entity.
     *
     * @return list<string>
     */
    public function supportedEntities(): array;

    /**
     * The shared Quenyx AI skill keys this module relies on. An empty list means the module uses the
     * shared platform skills without restriction.
     *
     * @return list<string>
     */
    public function supportedSkills(): array;

    /**
     * The provider keys this module supports. An empty list means the module uses whatever provider
     * the shared platform resolves (the standard case — no module pins a provider).
     *
     * @return list<string>
     */
    public function supportedProviders(): array;

    /**
     * The contextual AI actions a user can invoke for this module (capability key, the entity it
     * targets, label, and the workspace-scoped, UUID-only endpoint that serves it). Used to render
     * contextual "✨ Quenyx AI" actions and to drive dynamic navigation without hard-coding them.
     *
     * @return list<array<string, mixed>>
     */
    public function availableActions(): array;

    /**
     * Build the deterministic, workspace-scoped AI context for this module from REAL domain data.
     * The shared Quenyx AI runtime narrates this context; it is never fabricated, and when evidence
     * is missing the context says so. No AI provider is called here.
     *
     * @param  array<string, mixed>  $options  Optional, adapter-specific tuning (e.g. time windows).
     * @return array<string, mixed>
     */
    public function buildContext(Project $project, array $options = []): array;
}
