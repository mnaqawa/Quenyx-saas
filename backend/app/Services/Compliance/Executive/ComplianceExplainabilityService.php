<?php

namespace App\Services\Compliance\Executive;

use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Services\Compliance\Gap\GapAssessmentService;
use App\Services\Compliance\Recommendation\RecommendationGenerationService;

/**
 * Compliance explainability (QCIF Sprint 18).
 *
 * Answers the executive "why" questions — Why this recommendation? Why is this requirement
 * non-compliant? Which evidence supports it? Which rules fired? Which citations were used? — strictly
 * from the DETERMINISTIC engines already built (Gap Assessment + Recommendation). No AI is invoked;
 * every answer is traceable to a fixed evaluation/recommendation rule and to corpus provenance.
 */
class ComplianceExplainabilityService
{
    public function __construct(
        private readonly GapAssessmentService $gap,
        private readonly RecommendationGenerationService $recommendations,
    ) {}

    /**
     * Explain a single requirement: status, the rule that decided it, the evidence considered, the
     * resulting recommendations, and the corpus citations.
     *
     * @return array<string, mixed>
     */
    public function explainRequirement(?string $frameworkKey, ?string $releaseCode, int $projectId, string $requirementCode): array
    {
        $finding = $this->gap->assessRequirement($frameworkKey, $releaseCode, $projectId, $requirementCode)['finding'] ?? null;
        if (! is_array($finding)) {
            throw new ComplianceCorpusNotFoundException("Requirement not found in assessment: {$requirementCode}.");
        }

        $recommendations = $this->recommendations
            ->generateForRequirement($frameworkKey, $releaseCode, $projectId, $requirementCode)['recommendations'] ?? [];

        $citations = $this->citationsFor($finding);
        $rulesFired = array_values(array_filter(array_unique(array_merge(
            [$finding['evaluation_rule'] ?? null],
            array_map(static fn ($r) => $r['source_rule'] ?? null, $recommendations),
        ))));

        $evidenceSupporting = array_values(array_filter(
            (array) ($finding['evidence_considered'] ?? []),
            static fn ($e) => ($e['classification'] ?? null) === 'approved_valid',
        ));

        return [
            'context_type' => 'explainability',
            'subject' => 'requirement',
            'requirement' => $finding['requirement'] ?? null,
            'control' => $finding['control'] ?? null,
            'domain' => $finding['domain'] ?? null,
            'status' => $finding['status'] ?? null,
            'status_label_en' => $finding['status_label_en'] ?? null,
            'status_label_ar' => $finding['status_label_ar'] ?? null,
            'severity' => $finding['severity'] ?? null,
            'questions' => [
                $this->qa(
                    'Why is this requirement in its current compliance state?',
                    'لماذا هذا المتطلب في حالة الامتثال الحالية؟',
                    $finding['reason'] ?? null,
                ),
                $this->qa(
                    'Which evidence supports this requirement?',
                    'ما الأدلة التي تدعم هذا المتطلب؟',
                    $evidenceSupporting,
                ),
                $this->qa(
                    'Which rules fired?',
                    'ما القواعد التي تم تطبيقها؟',
                    $rulesFired,
                ),
                $this->qa(
                    'Which citations were used?',
                    'ما الاستشهادات المستخدمة؟',
                    $citations,
                ),
            ],
            'evidence_considered' => $finding['evidence_considered'] ?? [],
            'evidence_ignored' => $finding['evidence_ignored'] ?? [],
            'evidence_supporting' => $evidenceSupporting,
            'rules_fired' => $rulesFired,
            'recommendations' => $this->summarizeRecommendations($recommendations),
            'citations' => $citations,
            'determinism' => ['ai_used' => false, 'source' => 'gap_assessment + recommendation_engine'],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Explain why a requirement produced its recommendations (source rule + rationale + priority
     * basis + originating gap status).
     *
     * @return array<string, mixed>
     */
    public function explainRecommendation(?string $frameworkKey, ?string $releaseCode, int $projectId, string $requirementCode): array
    {
        $recommendations = $this->recommendations
            ->generateForRequirement($frameworkKey, $releaseCode, $projectId, $requirementCode)['recommendations'] ?? [];

        if ($recommendations === []) {
            throw new ComplianceCorpusNotFoundException("No recommendations found for requirement: {$requirementCode}.");
        }

        $detailed = array_map(function (array $r): array {
            return [
                'uuid' => $r['uuid'] ?? null,
                'requirement' => $r['requirement'] ?? null,
                'control' => $r['control'] ?? null,
                'recommendation_type' => $r['recommendation_type'] ?? null,
                'priority' => $r['priority'] ?? null,
                'priority_label_en' => $r['priority_label_en'] ?? null,
                'priority_label_ar' => $r['priority_label_ar'] ?? null,
                'gap_status' => $r['gap_status'] ?? null,
                'source_rule' => $r['source_rule'] ?? null,
                'evaluation_rule' => $r['evaluation_rule'] ?? null,
                'rationale_en' => $r['rationale_en'] ?? null,
                'rationale_ar' => $r['rationale_ar'] ?? null,
                'priority_basis_en' => $r['priority_basis_en'] ?? null,
                'priority_basis_ar' => $r['priority_basis_ar'] ?? null,
                'evidence_considered' => $r['evidence_considered'] ?? [],
            ];
        }, $recommendations);

        return [
            'context_type' => 'explainability',
            'subject' => 'recommendation',
            'requirement_code' => $requirementCode,
            'questions' => [
                $this->qa(
                    'Why did I receive this recommendation?',
                    'لماذا تلقيت هذه التوصية؟',
                    array_values(array_map(static fn ($r) => [
                        'recommendation_uuid' => $r['uuid'],
                        'source_rule' => $r['source_rule'],
                        'because_gap_status' => $r['gap_status'],
                        'rationale_en' => $r['rationale_en'],
                    ], $detailed)),
                ),
            ],
            'recommendations' => $detailed,
            'determinism' => ['ai_used' => false, 'source' => 'recommendation_engine'],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $finding
     * @return list<array<string, mixed>>
     */
    private function citationsFor(array $finding): array
    {
        $citations = [];

        $req = $finding['requirement'] ?? [];
        $provenance = $req['provenance'] ?? [];
        if (($provenance['official_reference'] ?? null) !== null || ($provenance['source_reference'] ?? null) !== null) {
            $citations[] = [
                'entity_type' => 'requirement',
                'entity_uuid' => $req['uuid'] ?? null,
                'entity_code' => $req['code'] ?? null,
                'official_reference' => $provenance['official_reference'] ?? null,
                'source_reference' => $provenance['source_reference'] ?? null,
                'source_page' => $provenance['source_page'] ?? null,
            ];
        }

        $control = $finding['control'] ?? null;
        if (is_array($control) && ($control['uuid'] ?? null) !== null) {
            $citations[] = [
                'entity_type' => 'control',
                'entity_uuid' => $control['uuid'],
                'entity_code' => $control['code'] ?? null,
            ];
        }

        return $citations;
    }

    /**
     * @param  list<array<string, mixed>>  $recommendations
     * @return list<array<string, mixed>>
     */
    private function summarizeRecommendations(array $recommendations): array
    {
        return array_values(array_map(static fn ($r) => [
            'uuid' => $r['uuid'] ?? null,
            'recommendation_type' => $r['recommendation_type'] ?? null,
            'priority' => $r['priority'] ?? null,
            'source_rule' => $r['source_rule'] ?? null,
            'title_en' => $r['title_en'] ?? null,
            'title_ar' => $r['title_ar'] ?? null,
        ], $recommendations));
    }

    /**
     * @return array<string, mixed>
     */
    private function qa(string $questionEn, string $questionAr, mixed $answer): array
    {
        return [
            'question_en' => $questionEn,
            'question_ar' => $questionAr,
            'answer' => $answer,
        ];
    }
}
