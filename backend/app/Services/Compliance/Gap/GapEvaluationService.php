<?php

namespace App\Services\Compliance\Gap;

use App\Enums\Compliance\Gap\ComplianceGapSeverity;
use App\Enums\Compliance\Gap\ComplianceGapStatus;

/**
 * The deterministic per-requirement evaluation engine (QCIF Sprint 12).
 *
 * Given a requirement's applicable evidence descriptors and its corpus evidence expectations, it
 * returns exactly one gap status plus the full explainability record (rule key, reason, evidence
 * considered vs ignored). The rules are fixed and ordered — the same inputs ALWAYS produce the
 * same output. There is no AI, no probability, no confidence percentage, no subjective judgement.
 *
 * Evidence classification (computed by the caller, passed in as `classification`):
 *   approved_valid → status approved AND not expired
 *   expired        → past expiry (or status expired)
 *   rejected       → status rejected
 *   pending        → registered | collected | validated, not expired (awaiting approval)
 *   archived       → status archived (always ignored)
 *
 * @phpstan-type EvidenceDescriptor array{uuid: string, status: string, classification: string, origin: string, evidence_type_id: int|null}
 * @phpstan-type ExpectationDescriptor array{is_required: bool, evidence_type_id: int|null, code: string|null}
 */
class GapEvaluationService
{
    public const RULE_NO_EVIDENCE = 'no_evidence_linked';
    public const RULE_ALL_REQUIRED_SATISFIED = 'all_required_expectations_satisfied';
    public const RULE_SOME_REQUIRED_SATISFIED = 'some_required_expectations_satisfied';
    public const RULE_APPROVED_NO_REQUIRED = 'approved_valid_no_required_expectations';
    public const RULE_APPROVED_TYPES_UNMATCHED = 'approved_evidence_present_required_types_unmatched';
    public const RULE_PENDING = 'evidence_pending_validation';
    public const RULE_EXPIRED = 'evidence_expired';
    public const RULE_REJECTED = 'evidence_rejected';
    public const RULE_INDETERMINATE = 'indeterminate';

    /**
     * @param  list<array<string, mixed>>  $evidence  EvidenceDescriptor[]
     * @param  list<array<string, mixed>>  $expectations  ExpectationDescriptor[]
     * @return array{
     *     status: ComplianceGapStatus,
     *     severity: ComplianceGapSeverity,
     *     evaluation_rule: string,
     *     reason: string,
     *     evidence_considered: list<array<string, mixed>>,
     *     evidence_ignored: list<array<string, mixed>>
     * }
     */
    public function evaluate(array $evidence, array $expectations = []): array
    {
        // Bucket evidence by classification (deterministic, order-preserving).
        $buckets = ['approved_valid' => [], 'pending' => [], 'expired' => [], 'rejected' => [], 'archived' => []];
        foreach ($evidence as $item) {
            $class = (string) ($item['classification'] ?? 'pending');
            if (! array_key_exists($class, $buckets)) {
                $class = 'pending';
            }
            $buckets[$class][] = $item;
        }

        // Archived evidence is never decision-relevant.
        $ignored = array_map(fn ($e) => $this->ignoredNode($e, 'archived'), $buckets['archived']);

        if ($evidence === [] || $this->onlyArchived($buckets)) {
            return $this->result(
                ComplianceGapStatus::NoEvidence,
                self::RULE_NO_EVIDENCE,
                'No evidence is linked to this requirement.',
                [],
                $ignored,
            );
        }

        if ($buckets['approved_valid'] !== []) {
            return $this->evaluateApproved($buckets, $expectations, $ignored);
        }

        if ($buckets['pending'] !== []) {
            return $this->result(
                ComplianceGapStatus::EvidencePendingValidation,
                self::RULE_PENDING,
                'Evidence exists but is awaiting validation/approval; no approved, in-date evidence is present.',
                array_map(fn ($e) => $this->consideredNode($e, 'pending_validation'), $buckets['pending']),
                array_merge(
                    $ignored,
                    array_map(fn ($e) => $this->ignoredNode($e, 'expired'), $buckets['expired']),
                    array_map(fn ($e) => $this->ignoredNode($e, 'rejected'), $buckets['rejected']),
                ),
            );
        }

        if ($buckets['expired'] !== []) {
            return $this->result(
                ComplianceGapStatus::EvidenceExpired,
                self::RULE_EXPIRED,
                'All applicable evidence is past its expiry date; no in-date evidence is present.',
                array_map(fn ($e) => $this->consideredNode($e, 'expired'), $buckets['expired']),
                array_map(fn ($e) => $this->ignoredNode($e, 'rejected'), $buckets['rejected']),
            );
        }

        if ($buckets['rejected'] !== []) {
            return $this->result(
                ComplianceGapStatus::EvidenceRejected,
                self::RULE_REJECTED,
                'The only applicable evidence has been rejected.',
                array_map(fn ($e) => $this->consideredNode($e, 'rejected'), $buckets['rejected']),
                $ignored,
            );
        }

        return $this->result(
            ComplianceGapStatus::Unknown,
            self::RULE_INDETERMINATE,
            'Evidence is present but could not be classified into a known state.',
            [],
            $ignored,
        );
    }

    /**
     * Map a gap status to its single, fixed severity. Exposed for aggregate (coverage) nodes.
     */
    public function severityFor(ComplianceGapStatus $status): ComplianceGapSeverity
    {
        return ComplianceGapSeverity::forStatus($status);
    }

    /**
     * Decision branch when at least one approved, in-date evidence exists. Partial vs full is
     * decided by REQUIRED corpus expectations matched by evidence_type_id.
     *
     * @param  array<string, list<array<string, mixed>>>  $buckets
     * @param  list<array<string, mixed>>  $expectations
     * @param  list<array<string, mixed>>  $ignored
     * @return array<string, mixed>
     */
    private function evaluateApproved(array $buckets, array $expectations, array $ignored): array
    {
        $approved = $buckets['approved_valid'];
        $considered = array_map(fn ($e) => $this->consideredNode($e, 'approved_valid'), $approved);

        $otherIgnored = array_merge(
            $ignored,
            array_map(fn ($e) => $this->ignoredNode($e, 'superseded_by_approved'), $buckets['pending']),
            array_map(fn ($e) => $this->ignoredNode($e, 'expired'), $buckets['expired']),
            array_map(fn ($e) => $this->ignoredNode($e, 'rejected'), $buckets['rejected']),
        );

        $required = array_values(array_filter($expectations, fn ($e) => (bool) ($e['is_required'] ?? false)));

        if ($required === []) {
            return $this->result(
                ComplianceGapStatus::Compliant,
                self::RULE_APPROVED_NO_REQUIRED,
                'At least one approved, in-date evidence is present and the corpus defines no required evidence expectations.',
                $considered,
                $otherIgnored,
            );
        }

        $approvedTypeIds = [];
        foreach ($approved as $e) {
            if (($e['evidence_type_id'] ?? null) !== null) {
                $approvedTypeIds[(int) $e['evidence_type_id']] = true;
            }
        }

        $satisfied = 0;
        foreach ($required as $expectation) {
            $typeId = $expectation['evidence_type_id'] ?? null;
            if ($typeId === null) {
                // Untyped required expectation: any approved, in-date evidence satisfies it.
                $satisfied++;
            } elseif (isset($approvedTypeIds[(int) $typeId])) {
                $satisfied++;
            }
        }

        $requiredCount = count($required);

        if ($satisfied >= $requiredCount) {
            return $this->result(
                ComplianceGapStatus::Compliant,
                self::RULE_ALL_REQUIRED_SATISFIED,
                "All {$requiredCount} required evidence expectation(s) are satisfied by approved, in-date evidence.",
                $considered,
                $otherIgnored,
            );
        }

        if ($satisfied > 0) {
            return $this->result(
                ComplianceGapStatus::PartiallyCompliant,
                self::RULE_SOME_REQUIRED_SATISFIED,
                "{$satisfied} of {$requiredCount} required evidence expectation(s) are satisfied by approved, in-date evidence.",
                $considered,
                $otherIgnored,
            );
        }

        return $this->result(
            ComplianceGapStatus::PartiallyCompliant,
            self::RULE_APPROVED_TYPES_UNMATCHED,
            "Approved, in-date evidence is present but does not match any of the {$requiredCount} required evidence type(s).",
            $considered,
            $otherIgnored,
        );
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $buckets
     */
    private function onlyArchived(array $buckets): bool
    {
        return $buckets['approved_valid'] === []
            && $buckets['pending'] === []
            && $buckets['expired'] === []
            && $buckets['rejected'] === []
            && $buckets['archived'] !== [];
    }

    /**
     * @param  list<array<string, mixed>>  $considered
     * @param  list<array<string, mixed>>  $ignored
     * @return array<string, mixed>
     */
    private function result(ComplianceGapStatus $status, string $rule, string $reason, array $considered, array $ignored): array
    {
        return [
            'status' => $status,
            'severity' => ComplianceGapSeverity::forStatus($status),
            'evaluation_rule' => $rule,
            'reason' => $reason,
            'evidence_considered' => $considered,
            'evidence_ignored' => $ignored,
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function consideredNode(array $evidence, string $reason): array
    {
        return [
            'uuid' => $evidence['uuid'] ?? null,
            'status' => $evidence['status'] ?? null,
            'origin' => $evidence['origin'] ?? null,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function ignoredNode(array $evidence, string $reason): array
    {
        return [
            'uuid' => $evidence['uuid'] ?? null,
            'status' => $evidence['status'] ?? null,
            'origin' => $evidence['origin'] ?? null,
            'reason' => $reason,
        ];
    }
}
