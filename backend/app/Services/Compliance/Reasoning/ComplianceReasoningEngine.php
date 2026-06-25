<?php

namespace App\Services\Compliance\Reasoning;

use App\DataTransferObjects\Compliance\Reasoning\ComplianceReasoningContext;
use App\DataTransferObjects\Compliance\Reasoning\ComplianceReasoningDecision;
use App\DataTransferObjects\Compliance\Reasoning\ComplianceReasoningExplanation;
use App\DataTransferObjects\Compliance\Reasoning\ReasoningFinding;
use App\DataTransferObjects\Compliance\Reasoning\ReasoningOutput;
use App\DataTransferObjects\Compliance\Reasoning\ReasoningRecommendation;
use App\DataTransferObjects\Compliance\Reasoning\ReasoningTrace;
use App\DataTransferObjects\Compliance\Reasoning\ReasoningTraceNode;
use App\Enums\Compliance\Reasoning\ComplianceReasoningDecisionType;
use Ramsey\Uuid\Uuid;

/**
 * The Compliance Reasoning Engine (QCIF Sprint 16) — the deterministic decision layer between
 * Retrieval and the LLM.
 *
 * Given an intent + retrieval result + AI skill outputs (all wrapped in a
 * {@see ComplianceReasoningContext}), it decides WHAT must be answered and produces structured
 * reasoning: facts, findings, recommendations, missing information, citations, an answer strategy,
 * and an explainable reasoning trace. It contains NO natural-language answer, makes NO AI/provider
 * calls, performs NO retrieval, and touches NO database. Same input → same output (uuid5 IDs).
 */
class ComplianceReasoningEngine
{
    private const NS = 'qcif:sprint16:reasoning:engine';

    public function __construct(
        private readonly ComplianceReasoningPlanner $planner,
        private readonly ComplianceReasoningRuleSet $ruleSet,
    ) {}

    public function reason(ComplianceReasoningContext $context): ReasoningOutput
    {
        $decision = $this->planner->decide($context);

        if (! $decision->isSupported()) {
            return $this->unsupportedOutput($context, $decision);
        }

        $facts = $this->extractFacts($context, $decision);
        $rules = $this->ruleSet->apply($context, $decision);
        $citations = $this->mergeCitations($context);

        /** @var list<ReasoningFinding> $findings */
        $findings = $rules['findings'];
        /** @var list<ReasoningRecommendation> $recommendations */
        $recommendations = $rules['recommendations'];
        $missing = $rules['missing'];

        $trace = $this->buildTrace($context, $decision, $facts, $findings, $recommendations, $missing, $citations);
        $explanation = $this->buildExplanation($decision, $facts, $findings, $recommendations, $missing, $rules['applied']);

        $warnings = array_values(array_unique([
            ...$rules['warnings'],
            ...array_values($context->scope['warnings'] ?? []),
        ]));

        return new ReasoningOutput(
            decision: $decision,
            facts: $facts,
            findings: $findings,
            recommendations: $recommendations,
            missingInformation: $missing,
            citations: $citations,
            guardrails: $context->guardrails,
            warnings: $warnings,
            trace: $trace,
            explanation: $explanation,
            generatedAt: now()->toIso8601String(),
        );
    }

    // -------------------------------------------------------------------------
    // Facts
    // -------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function extractFacts(ComplianceReasoningContext $context, ComplianceReasoningDecision $decision): array
    {
        $facts = [];

        if ($context->revisionUuid() !== null) {
            $facts[] = $this->fact($context, 'scope', 'corpus_scope', [
                'framework_key' => $context->scope['framework_key'] ?? null,
                'release_code' => $context->scope['release_code'] ?? null,
                'revision_uuid' => $context->revisionUuid(),
            ]);
        }

        $gap = $context->payload('gap_assessment');
        $reco = $context->payload('recommendation');
        $evidence = $context->payload('evidence');
        $corpus = $context->payload('corpus_search');

        switch ($decision->type) {
            case ComplianceReasoningDecisionType::ControlExplanation:
            case ComplianceReasoningDecisionType::FrameworkMapping:
            case ComplianceReasoningDecisionType::KnowledgeNavigation:
                $control = is_array($corpus['control'] ?? null) ? $corpus['control'] : null;
                if ($control !== null) {
                    $facts[] = $this->fact($context, 'corpus', 'control_profile', [
                        'entity_code' => $control['display_code'] ?? $control['code'] ?? $context->code,
                        'title_en' => $control['title_en'] ?? null,
                        'title_ar' => $control['title_ar'] ?? null,
                        'requirement_count' => is_array($corpus['requirements'] ?? null) ? count($corpus['requirements']) : 0,
                    ]);
                }
                $facts[] = $this->fact($context, 'retrieval', 'retrieved_chunks', [
                    'chunk_count' => count($context->retrievalChunks),
                ]);
                break;

            case ComplianceReasoningDecisionType::GapAnalysis:
                $facts[] = $this->fact($context, 'gap', 'gap_totals', [
                    'requirements' => $this->m($gap, ['summary', 'totals', 'requirements']),
                    'satisfied' => $this->m($gap, ['summary', 'totals', 'satisfied']),
                    'gaps' => $this->m($gap, ['summary', 'totals', 'gaps']),
                ]);
                break;

            case ComplianceReasoningDecisionType::EvidenceReview:
                $facts[] = $this->fact($context, 'evidence', 'evidence_totals', [
                    'evidence' => $this->m($evidence, ['counts', 'evidence']),
                    'outstanding_gaps' => $this->m($gap, ['summary', 'totals', 'gaps']),
                ]);
                break;

            case ComplianceReasoningDecisionType::Recommendation:
                $facts[] = $this->fact($context, 'recommendation', 'recommendation_totals', [
                    'recommendations' => $this->m($reco, ['summary', 'totals', 'recommendations']),
                    'critical' => $this->m($reco, ['summary', 'totals', 'by_priority', 'critical']),
                    'high' => $this->m($reco, ['summary', 'totals', 'by_priority', 'high']),
                    'medium' => $this->m($reco, ['summary', 'totals', 'by_priority', 'medium']),
                ]);
                break;

            case ComplianceReasoningDecisionType::SearchSummary:
                $facts[] = $this->fact($context, 'corpus', 'search_results', [
                    'citation_count' => count($context->corpusCitations),
                    'chunk_count' => count($context->retrievalChunks),
                ]);
                break;

            default:
                break;
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function fact(ComplianceReasoningContext $context, string $source, string $label, array $value): array
    {
        return [
            'uuid' => $this->mint('fact', $source.':'.$label, $context),
            'label' => $label,
            'source' => $source,
            'value' => $value,
        ];
    }

    // -------------------------------------------------------------------------
    // Citations
    // -------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function mergeCitations(ComplianceReasoningContext $context): array
    {
        $merged = [];
        $seen = [];

        $chunkCitations = [];
        foreach ($context->retrievalChunks as $chunk) {
            foreach ($chunk['citations'] ?? [] as $citation) {
                if (is_array($citation)) {
                    $chunkCitations[] = $citation;
                }
            }
        }

        foreach ([...$context->corpusCitations, ...$chunkCitations] as $citation) {
            $key = json_encode($citation);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $merged[] = $citation;
            }
        }

        return $merged;
    }

    // -------------------------------------------------------------------------
    // Trace
    // -------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<ReasoningFinding>  $findings
     * @param  list<ReasoningRecommendation>  $recommendations
     * @param  list<array<string, mixed>>  $missing
     * @param  list<array<string, mixed>>  $citations
     */
    private function buildTrace(
        ComplianceReasoningContext $context,
        ComplianceReasoningDecision $decision,
        array $facts,
        array $findings,
        array $recommendations,
        array $missing,
        array $citations,
    ): ReasoningTrace {
        $rootUuid = $this->mint('trace', 'root:'.$decision->type->value, $context);

        $factChildren = [];
        foreach ($facts as $fact) {
            $factChildren[] = new ReasoningTraceNode(
                uuid: (string) $fact['uuid'],
                reason: 'fact: '.$fact['label'],
                source: 'fact:'.$fact['source'],
                parent: $rootUuid,
                metadata: ['value' => $fact['value']],
            );
        }

        $findingChildren = [];
        foreach ($findings as $finding) {
            $findingChildren[] = new ReasoningTraceNode(
                uuid: $finding->uuid,
                reason: 'finding: '.$finding->code.' ('.$finding->severity.')',
                source: 'rule:'.$finding->ruleId,
                citations: $finding->citations,
                parent: $rootUuid,
            );
        }

        $recoChildren = [];
        foreach ($recommendations as $reco) {
            $recoChildren[] = new ReasoningTraceNode(
                uuid: $reco->uuid,
                reason: 'recommendation: '.$reco->action.' ('.$reco->priority.')',
                source: 'rule:'.$reco->ruleId,
                citations: $reco->citations,
                parent: $rootUuid,
            );
        }

        $missingChildren = [];
        foreach ($missing as $item) {
            $missingChildren[] = new ReasoningTraceNode(
                uuid: (string) $item['uuid'],
                reason: 'missing: '.$item['code'],
                source: 'missing',
                parent: $rootUuid,
            );
        }

        $children = [];
        $children[] = $this->group($context, $rootUuid, 'facts', $factChildren);
        $children[] = $this->group($context, $rootUuid, 'findings', $findingChildren);
        $children[] = $this->group($context, $rootUuid, 'recommendations', $recoChildren);
        $children[] = $this->group($context, $rootUuid, 'missing_information', $missingChildren);

        $root = new ReasoningTraceNode(
            uuid: $rootUuid,
            reason: 'decision: '.$decision->type->value.' → strategy: '.$decision->answerStrategy,
            source: 'decision',
            citations: $citations,
            parent: null,
            children: $children,
            metadata: ['notes' => $decision->notes],
        );

        return new ReasoningTrace($root);
    }

    /**
     * @param  list<ReasoningTraceNode>  $children
     */
    private function group(ComplianceReasoningContext $context, string $parent, string $name, array $children): ReasoningTraceNode
    {
        return new ReasoningTraceNode(
            uuid: $this->mint('trace-group', $name, $context),
            reason: 'group: '.$name.' ('.count($children).')',
            source: 'group',
            parent: $parent,
            children: $children,
        );
    }

    // -------------------------------------------------------------------------
    // Explanation
    // -------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<ReasoningFinding>  $findings
     * @param  list<ReasoningRecommendation>  $recommendations
     * @param  list<array<string, mixed>>  $missing
     * @param  list<string>  $applied
     */
    private function buildExplanation(
        ComplianceReasoningDecision $decision,
        array $facts,
        array $findings,
        array $recommendations,
        array $missing,
        array $applied,
    ): ComplianceReasoningExplanation {
        $rules = $applied === [] ? 'no rules fired' : implode(', ', $applied);

        return new ComplianceReasoningExplanation(
            decisionType: $decision->type->value,
            answerStrategy: $decision->answerStrategy,
            appliedRuleIds: $applied,
            factCount: count($facts),
            findingCount: count($findings),
            recommendationCount: count($recommendations),
            missingInformationCount: count($missing),
            summaryEn: sprintf('Decision %s resolved deterministically; %d fact(s), %d finding(s), %d recommendation(s). Rules: %s.',
                $decision->type->value, count($facts), count($findings), count($recommendations), $rules),
            summaryAr: sprintf('تم تحديد القرار %s بشكل حتمي؛ %d حقيقة، %d نتيجة، %d توصية.',
                $decision->type->value, count($facts), count($findings), count($recommendations)),
        );
    }

    private function unsupportedOutput(ComplianceReasoningContext $context, ComplianceReasoningDecision $decision): ReasoningOutput
    {
        $rootUuid = $this->mint('trace', 'root:unsupported', $context);
        $root = new ReasoningTraceNode(
            uuid: $rootUuid,
            reason: 'decision: unsupported → strategy: '.$decision->answerStrategy,
            source: 'decision',
            parent: null,
        );

        $explanation = new ComplianceReasoningExplanation(
            decisionType: $decision->type->value,
            answerStrategy: $decision->answerStrategy,
            appliedRuleIds: [],
            factCount: 0,
            findingCount: 0,
            recommendationCount: 0,
            missingInformationCount: 0,
            summaryEn: 'The question is outside the supported deterministic decision set.',
            summaryAr: 'السؤال خارج مجموعة القرارات الحتمية المدعومة.',
        );

        return new ReasoningOutput(
            decision: $decision,
            facts: [],
            findings: [],
            recommendations: [],
            missingInformation: [],
            citations: [],
            guardrails: $context->guardrails,
            warnings: ['unsupported_intent'],
            trace: new ReasoningTrace($root),
            explanation: $explanation,
            generatedAt: now()->toIso8601String(),
        );
    }

    private function mint(string $kind, string $code, ComplianceReasoningContext $context): string
    {
        return (string) Uuid::uuid5(
            Uuid::uuid5(Uuid::NAMESPACE_URL, self::NS),
            implode('|', [$kind, $code, $context->signature(), $context->intent->value]),
        );
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @param  list<string|int>  $path
     */
    private function m(?array $data, array $path): int
    {
        $current = $data;
        foreach ($path as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return 0;
            }
            $current = $current[$key];
        }

        return is_numeric($current) ? (int) $current : 0;
    }
}
