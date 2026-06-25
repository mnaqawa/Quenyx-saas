<?php

namespace Tests\Unit;

use App\Enums\Compliance\Copilot\ComplianceCopilotIntent;
use App\Services\Compliance\Copilot\ComplianceCopilotCitationVerifier;
use App\Services\Compliance\Copilot\ComplianceCopilotPlanner;
use App\Services\Compliance\Copilot\ComplianceCopilotResponseValidator;
use App\Services\Compliance\Copilot\ComplianceCopilotSkillSelector;
use Tests\TestCase;

/**
 * DB-free, AI-free unit tests for the deterministic Compliance Copilot v0 core (QCIF Sprint 14):
 * intent classification, skill selection, and citation enforcement (fail closed).
 */
class CopilotPlannerTest extends TestCase
{
    private ComplianceCopilotPlanner $planner;

    private ComplianceCopilotSkillSelector $selector;

    private ComplianceCopilotCitationVerifier $verifier;

    private ComplianceCopilotResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new ComplianceCopilotPlanner();
        $this->selector = new ComplianceCopilotSkillSelector();
        $this->verifier = new ComplianceCopilotCitationVerifier();
        $this->validator = new ComplianceCopilotResponseValidator();
    }

    public function test_classifies_each_supported_intent_deterministically(): void
    {
        $cases = [
            'Explain control 1-1-1' => ComplianceCopilotIntent::ControlExplanation,
            'Summarize our compliance gaps' => ComplianceCopilotIntent::GapSummary,
            'What evidence do we have for control 2-8-4?' => ComplianceCopilotIntent::EvidenceStatus,
            'What should we fix first?' => ComplianceCopilotIntent::RecommendationSummary,
            'Find controls related to access management' => ComplianceCopilotIntent::SearchCorpus,
            'What is the weather today?' => ComplianceCopilotIntent::Unsupported,
        ];

        foreach ($cases as $message => $expected) {
            $plan = $this->planner->classify($message);
            $this->assertSame($expected, $plan['intent'], "Message: {$message}");
            // Determinism: same input → same output.
            $this->assertSame($expected, $this->planner->classify($message)['intent']);
        }
    }

    public function test_extracts_control_code(): void
    {
        $this->assertSame('1-1-1', $this->planner->classify('Explain control 1-1-1')['code']);
        $this->assertSame('2-8-4', $this->planner->classify('Evidence for control 2-8-4')['code']);
    }

    public function test_intent_selects_canonical_skills(): void
    {
        $this->assertSame(['corpus_search', 'knowledge_graph'], $this->selector->canonicalSkills(ComplianceCopilotIntent::ControlExplanation));
        $this->assertSame(['gap_assessment', 'recommendation'], $this->selector->canonicalSkills(ComplianceCopilotIntent::GapSummary));
        $this->assertSame(['evidence', 'gap_assessment'], $this->selector->canonicalSkills(ComplianceCopilotIntent::EvidenceStatus));
        $this->assertSame(['gap_assessment', 'recommendation'], $this->selector->canonicalSkills(ComplianceCopilotIntent::RecommendationSummary));
        $this->assertSame(['corpus_search', 'knowledge_graph', 'framework_mapping'], $this->selector->canonicalSkills(ComplianceCopilotIntent::SearchCorpus));
    }

    public function test_select_builds_requests_with_expected_skill_keys(): void
    {
        $plan = $this->planner->classify('Explain control 1-1-1');
        $requests = $this->selector->select($plan, 7, 'NCA-ECC', '2024');
        $keys = array_map(fn ($r) => $r->skill, $requests);
        $this->assertSame(['corpus_search', 'knowledge_graph'], $keys);

        $gapPlan = $this->planner->classify('Summarize our compliance gaps');
        $gapKeys = array_map(fn ($r) => $r->skill, $this->selector->select($gapPlan, 7, null, null));
        $this->assertSame(['gap_assessment', 'recommendation'], $gapKeys);
        // Workspace skills carry project_id and no scope is required.
        $this->assertSame(7, $this->selector->select($gapPlan, 7, null, null)[0]->param('project_id'));
    }

    public function test_unsupported_intent_selects_no_skills(): void
    {
        $plan = $this->planner->classify('Tell me a joke');
        $this->assertSame(ComplianceCopilotIntent::Unsupported, $plan['intent']);
        $this->assertSame([], $this->selector->select($plan, 1, null, null));
    }

    public function test_citation_enforcement_fails_closed_without_corpus_citations(): void
    {
        $result = $this->verifier->verify(
            ComplianceCopilotIntent::ControlExplanation,
            corpusCitations: [],
            groundingRefs: [],
            answerEn: 'Control 1-1-1 requires X.',
            answerAr: '',
            mode: 'mock',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('citation_validation_failed', $result['code']);
    }

    public function test_engine_intent_passes_with_grounding_reference(): void
    {
        $result = $this->verifier->verify(
            ComplianceCopilotIntent::GapSummary,
            corpusCitations: [],
            groundingRefs: [['type' => 'gap_context', 'skill' => 'gap_assessment']],
            answerEn: '5 requirements assessed.',
            answerAr: '',
            mode: 'mock',
        );

        $this->assertTrue($result['ok']);
        $this->assertNull($result['code']);
    }

    public function test_empty_answer_is_allowed(): void
    {
        $result = $this->verifier->verify(
            ComplianceCopilotIntent::SearchCorpus,
            corpusCitations: [],
            groundingRefs: [],
            answerEn: '',
            answerAr: '',
            mode: 'mock',
        );

        $this->assertTrue($result['ok']);
    }

    public function test_ai_mode_flags_uncited_answer_for_review(): void
    {
        $result = $this->verifier->verify(
            ComplianceCopilotIntent::ControlExplanation,
            corpusCitations: [['source_document_key' => 'NCA-ECC-2024', 'code' => '1-1-1']],
            groundingRefs: [],
            answerEn: 'Some unrelated answer with no reference tokens.',
            answerAr: '',
            mode: 'ai',
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['needs_review']);
        $this->assertContains('answer_may_reference_uncited_facts', $result['warnings']);
    }

    public function test_validator_emits_bilingual_and_disclaimer_warnings(): void
    {
        $warnings = $this->validator->validate(
            ['bilingual_required' => true, 'no_legal_advice_disclaimer_required' => true],
            answerEn: 'English only answer.',
            answerAr: '',
            mode: 'ai',
        );

        $this->assertContains('missing_arabic_answer', $warnings);
        $this->assertContains('missing_legal_disclaimer', $warnings);
    }
}
