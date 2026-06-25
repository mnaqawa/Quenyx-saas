<?php

namespace App\Services\Compliance\Executive;

use App\Enums\Compliance\CorpusRevisionStatus;
use App\Enums\Compliance\Reasoning\ComplianceReasoningDecisionType;
use App\Enums\Compliance\Retrieval\ComplianceRetrievalMode;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceControlObjective;
use App\Models\Compliance\ComplianceControlObjectiveMapping;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceEvidenceExpectation;
use App\Models\Compliance\ComplianceEvidenceType;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\ComplianceGuidanceItem;
use App\Models\Compliance\ComplianceRequirement;
use App\Models\Compliance\ComplianceSourceDocument;
use App\Models\Compliance\Gap\ComplianceGapAssessment;
use App\Models\Compliance\Rag\RagVectorChunk;
use App\Models\Compliance\Recommendation\ComplianceRecommendation;
use App\Services\Compliance\Reasoning\ComplianceReasoningRuleSet;

/**
 * Investor / platform metrics (QCIF Sprint 18) — REAL counts only.
 *
 * Every number here is a live count of what the QCIF engine actually contains or has produced. This
 * service NEVER fabricates customers, revenue, ROI, or benchmark figures. It exposes corpus scale,
 * engine capability, and platform usage — all derived deterministically from the database and config.
 */
class CompliancePlatformMetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        return [
            'corpus' => $this->corpusMetrics(),
            'knowledge_graph' => $this->knowledgeGraphMetrics(),
            'engine_capabilities' => $this->engineCapabilityMetrics(),
            'platform_usage' => $this->platformUsageMetrics(),
            'integrity' => [
                'fabricated_metrics' => false,
                'note' => 'All values are live counts from the QCIF engine. No customers, revenue, ROI, or benchmarks are reported.',
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function corpusMetrics(): array
    {
        return [
            'frameworks_onboarded' => ComplianceFramework::query()->count(),
            'framework_releases' => ComplianceFrameworkRelease::query()->count(),
            'active_corpus_revisions' => ComplianceCorpusRevision::query()->where('status', CorpusRevisionStatus::Active)->count(),
            'total_corpus_revisions' => ComplianceCorpusRevision::query()->count(),
            'domains' => ComplianceDomain::query()->count(),
            'controls' => ComplianceControl::query()->count(),
            'requirements' => ComplianceRequirement::query()->count(),
            'control_objectives' => ComplianceControlObjective::query()->count(),
            'guidance_items' => ComplianceGuidanceItem::query()->count(),
            'evidence_expectations' => ComplianceEvidenceExpectation::query()->count(),
            'evidence_types' => ComplianceEvidenceType::query()->count(),
            'source_documents' => ComplianceSourceDocument::query()->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function knowledgeGraphMetrics(): array
    {
        $domains = ComplianceDomain::query()->count();
        $controls = ComplianceControl::query()->count();
        $requirements = ComplianceRequirement::query()->count();
        $objectives = ComplianceControlObjective::query()->count();
        $mappings = ComplianceControlObjectiveMapping::query()->count();

        // Nodes = corpus entities; edges = structural parent links + objective mappings.
        $nodes = $domains + $controls + $requirements + $objectives;
        $edges = $controls /* domain→control */ + $requirements /* control→requirement */ + $mappings;

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'objective_mappings' => $mappings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function engineCapabilityMetrics(): array
    {
        $skills = (array) config('ai.skills.registered', []);
        $enabledSkills = array_filter($skills, static fn ($s) => (bool) ($s['enabled'] ?? false));

        $providers = (array) config('ai.providers', []);
        $implementedProviders = array_filter($providers, static fn ($p) => ! empty($p['class']));

        return [
            'ai_skills' => count($enabledSkills),
            'ai_skills_registered' => count($skills),
            'ai_providers_implemented' => count($implementedProviders),
            'reasoning_rules' => count(ComplianceReasoningRuleSet::catalog()),
            'reasoning_decision_types' => count(ComplianceReasoningDecisionType::cases()),
            'retrieval_modes' => count(ComplianceRetrievalMode::cases()),
            'retrieval_chunks_indexed' => RagVectorChunk::query()->count(),
            'rag_enabled' => (bool) config('ai.rag.enabled', false),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function platformUsageMetrics(): array
    {
        return [
            'gap_assessments_run' => ComplianceGapAssessment::query()->count(),
            'recommendations_generated' => ComplianceRecommendation::query()->count(),
        ];
    }
}
