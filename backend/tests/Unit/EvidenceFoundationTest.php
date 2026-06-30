<?php

namespace Tests\Unit;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\Enums\Compliance\Evidence\ComplianceEvidenceStatus;
use App\Exceptions\Ai\AiSkillException;
use App\Models\Compliance\Evidence\ComplianceEvidence;
use App\Services\AI\Skills\EvidenceSkill;
use App\Services\Compliance\Evidence\EvidenceLifecycleService;
use App\Services\Compliance\Evidence\EvidenceValidationService;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

/**
 * DB-free, AI-free unit tests for the Evidence Intelligence Foundation: status enum, lifecycle
 * transition rules + catalog, deterministic validation, and the EvidenceSkill contract. Uses
 * non-persisted models with pre-loaded relations so no database is touched.
 */
class EvidenceFoundationTest extends TestCase
{
    public function test_status_enum_has_the_seven_states(): void
    {
        $this->assertSame(
            ['registered', 'collected', 'validated', 'approved', 'expired', 'rejected', 'archived'],
            ComplianceEvidenceStatus::values(),
        );
        $this->assertTrue(ComplianceEvidenceStatus::Archived->isTerminal());
        $this->assertFalse(ComplianceEvidenceStatus::Registered->isTerminal());
    }

    public function test_lifecycle_transitions_are_enforced(): void
    {
        $lifecycle = new EvidenceLifecycleService();

        $this->assertTrue($lifecycle->canTransition(ComplianceEvidenceStatus::Registered, ComplianceEvidenceStatus::Collected));
        $this->assertTrue($lifecycle->canTransition(ComplianceEvidenceStatus::Validated, ComplianceEvidenceStatus::Approved));
        $this->assertFalse($lifecycle->canTransition(ComplianceEvidenceStatus::Registered, ComplianceEvidenceStatus::Approved));
        $this->assertFalse($lifecycle->canTransition(ComplianceEvidenceStatus::Archived, ComplianceEvidenceStatus::Collected));
    }

    public function test_status_catalog_lists_all_states_with_transitions(): void
    {
        $catalog = (new EvidenceLifecycleService())->statusCatalog();

        $this->assertCount(7, $catalog);
        $approved = collect($catalog)->firstWhere('value', 'approved');
        $this->assertContains('expired', $approved['allowed_transitions']);
        $archived = collect($catalog)->firstWhere('value', 'archived');
        $this->assertSame([], $archived['allowed_transitions']);
        $this->assertTrue($archived['is_terminal']);
    }

    public function test_validation_flags_missing_title_and_no_relationships(): void
    {
        $evidence = $this->evidence(['title' => '']);
        $result = (new EvidenceValidationService())->validate($evidence);

        $this->assertFalse($result['is_valid']);
        $codes = array_column($result['issues'], 'code');
        $this->assertContains('missing_title', $codes);
        $this->assertContains('no_relationships', $codes);
    }

    public function test_validation_passes_for_complete_evidence(): void
    {
        $evidence = $this->evidence([
            'title' => 'Firewall config export',
            'evidence_type_id' => 1,
            'source' => 'manual',
            'control_id' => 5,
        ]);

        $result = (new EvidenceValidationService())->validate($evidence);
        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['issues']);
    }

    public function test_validation_detects_expiry(): void
    {
        $validation = new EvidenceValidationService();

        $expired = $this->evidence(['title' => 'X', 'control_id' => 1, 'expires_at' => now()->subDay()]);
        $this->assertTrue($validation->isExpired($expired));

        $fresh = $this->evidence(['title' => 'X', 'control_id' => 1, 'expires_at' => now()->addDay()]);
        $this->assertFalse($validation->isExpired($fresh));
    }

    public function test_evidence_skill_contract(): void
    {
        $skill = new EvidenceSkill();
        $this->assertSame('evidence', $skill->key());
        $this->assertSame(['evidence_context'], $skill->supportedContextTypes());
        $this->assertTrue($skill->supports(new AiSkillRequest(contextType: 'evidence_context')));
        $this->assertTrue($skill->health());
    }

    public function test_evidence_skill_requires_workspace(): void
    {
        $this->expectException(AiSkillException::class);
        (new EvidenceSkill())->execute(new AiSkillRequest(skill: 'evidence'));
    }

    /**
     * Build a non-persisted evidence record with relationships pre-loaded (empty) so validation
     * never touches the database.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function evidence(array $attributes): ComplianceEvidence
    {
        $evidence = new ComplianceEvidence(array_merge(['title' => 'Evidence'], $attributes));
        $evidence->status = ComplianceEvidenceStatus::Registered;
        if (array_key_exists('expires_at', $attributes)) {
            $evidence->expires_at = $attributes['expires_at'];
        }
        $evidence->setRelation('relationships', new Collection());

        return $evidence;
    }
}
