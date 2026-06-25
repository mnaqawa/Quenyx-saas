<?php

namespace App\Services\Compliance\Copilot;

use App\Enums\Compliance\CorpusRevisionStatus;
use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Services\Compliance\Corpus\ComplianceFrameworkReleaseResolver;
use App\Services\Compliance\ComplianceCorpusQueryService;

/**
 * Resolves the (framework, release, active revision) scope for a Copilot turn (QCIF Sprint 14.1).
 *
 * This is the ONLY Copilot service permitted to touch the database. The Copilot core
 * (Service/Planner/SkillSelector/CitationVerifier/ResponseValidator) stays DB-free and receives
 * the already-resolved scope from here.
 *
 * Resolution order:
 *   1. Explicit framework + release on the request  → validate (source = explicit).
 *   2. Otherwise the configured safe default (NCA ECC-2:2024) if it exists (source = defaulted).
 *   3. Otherwise the single framework release that has an active corpus revision (source = defaulted).
 *   4. Otherwise unresolved — engine intents still work; corpus/graph/mapping intents fail closed.
 *
 * An explicit but invalid framework/release is reported as a hard error (`scope_unresolved`) so the
 * caller fails clearly; a missing-with-no-default situation is a soft `unresolved` (no hard error).
 */
class ComplianceCopilotScopeResolver
{
    public function __construct(
        private readonly ComplianceCorpusQueryService $query = new ComplianceCorpusQueryService(),
        private readonly ComplianceFrameworkReleaseResolver $releaseResolver = new ComplianceFrameworkReleaseResolver(),
    ) {}

    /**
     * @return array{framework_key: ?string, release_code: ?string, revision_uuid: ?string, framework_title: ?string, release_title: ?string, source: string, warnings: list<string>, resolved: bool, error_code: ?string}
     */
    public function resolve(?string $framework, ?string $release): array
    {
        $framework = $this->clean($framework);
        $release = $this->clean($release);

        if ($framework !== null && $release !== null) {
            return $this->resolveExplicit($framework, $release);
        }

        return $this->resolveDefault();
    }

    /**
     * @return array{framework_key: ?string, release_code: ?string, revision_uuid: ?string, framework_title: ?string, release_title: ?string, source: string, warnings: list<string>, resolved: bool, error_code: ?string}
     */
    private function resolveExplicit(string $framework, string $release): array
    {
        try {
            $releaseModel = $this->query->resolveRelease($framework, $release);
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->unresolved(
                source: 'invalid',
                warnings: ['scope_not_found'],
                errorCode: 'scope_unresolved',
            );
        }

        return $this->fromRelease($releaseModel, 'explicit', []);
    }

    /**
     * @return array{framework_key: ?string, release_code: ?string, revision_uuid: ?string, framework_title: ?string, release_title: ?string, source: string, warnings: list<string>, resolved: bool, error_code: ?string}
     */
    private function resolveDefault(): array
    {
        $defaultFramework = (string) config('ai.copilot.default_scope.framework', 'nca-ecc');
        $defaultRelease = (string) config('ai.copilot.default_scope.release', '2:2024');

        $releaseModel = $this->releaseResolver->resolve($defaultFramework, $defaultRelease);
        if ($releaseModel !== null) {
            $releaseModel->loadMissing('framework');

            return $this->fromRelease($releaseModel, 'defaulted', ['default_scope_used']);
        }

        $primary = $this->resolvePrimaryRelease();
        if ($primary !== null) {
            return $this->fromRelease($primary, 'defaulted', ['default_scope_used', 'default_scope_primary_release']);
        }

        return $this->unresolved(
            source: 'unresolved',
            warnings: ['scope_unresolved_no_default'],
            errorCode: null,
        );
    }

    /**
     * The single framework release that currently has an active corpus revision; null if none/ambiguous.
     */
    private function resolvePrimaryRelease(): ?ComplianceFrameworkRelease
    {
        $releaseIds = ComplianceCorpusRevision::query()
            ->where('status', CorpusRevisionStatus::Active)
            ->pluck('framework_release_id')
            ->unique()
            ->values();

        if ($releaseIds->count() !== 1) {
            return null;
        }

        return ComplianceFrameworkRelease::query()
            ->with('framework')
            ->whereKey($releaseIds->first())
            ->first();
    }

    private function activeRevisionUuid(ComplianceFrameworkRelease $release): ?string
    {
        $revision = ComplianceCorpusRevision::query()
            ->where('framework_release_id', $release->id)
            ->where('status', CorpusRevisionStatus::Active)
            ->orderByDesc('revision_number')
            ->first();

        return $revision?->uuid;
    }

    /**
     * @param  list<string>  $warnings
     * @return array{framework_key: ?string, release_code: ?string, revision_uuid: ?string, framework_title: ?string, release_title: ?string, source: string, warnings: list<string>, resolved: bool, error_code: ?string}
     */
    private function fromRelease(ComplianceFrameworkRelease $release, string $source, array $warnings): array
    {
        $revisionUuid = $this->activeRevisionUuid($release);
        if ($revisionUuid === null) {
            $warnings[] = 'no_active_revision';
        }

        return [
            'framework_key' => (string) $release->framework?->key,
            'release_code' => (string) $release->version_code,
            'revision_uuid' => $revisionUuid,
            'framework_title' => $release->framework?->title_en,
            'release_title' => $release->title_en,
            'source' => $source,
            'warnings' => array_values(array_unique($warnings)),
            'resolved' => true,
            'error_code' => null,
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @return array{framework_key: ?string, release_code: ?string, revision_uuid: ?string, framework_title: ?string, release_title: ?string, source: string, warnings: list<string>, resolved: bool, error_code: ?string}
     */
    private function unresolved(string $source, array $warnings, ?string $errorCode): array
    {
        return [
            'framework_key' => null,
            'release_code' => null,
            'revision_uuid' => null,
            'framework_title' => null,
            'release_title' => null,
            'source' => $source,
            'warnings' => $warnings,
            'resolved' => false,
            'error_code' => $errorCode,
        ];
    }

    private function clean(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
