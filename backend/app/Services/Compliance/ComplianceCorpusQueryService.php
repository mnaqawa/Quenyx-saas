<?php

namespace App\Services\Compliance;

use App\Enums\Compliance\CorpusRevisionStatus;
use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\ComplianceSourceDocument;
use App\Services\Compliance\Corpus\ComplianceFrameworkReleaseResolver;
use Illuminate\Support\Facades\DB;

class ComplianceCorpusQueryService
{
    public function __construct(
        private readonly ComplianceFrameworkReleaseResolver $releaseResolver = new ComplianceFrameworkReleaseResolver(),
        private readonly ComplianceCorpusBatchMetadataReader $batchMetadataReader = new ComplianceCorpusBatchMetadataReader(),
    ) {}

    public function resolveRelease(string $frameworkKey, string $releaseCode): ComplianceFrameworkRelease
    {
        $release = $this->releaseResolver->resolve($frameworkKey, $releaseCode);
        if ($release === null) {
            throw new ComplianceCorpusNotFoundException(
                "Framework release not found for framework={$frameworkKey}, release={$releaseCode}."
            );
        }

        $release->loadMissing('framework.authority');

        return $release;
    }

    public function resolveReleaseFramework(string $frameworkKey): ComplianceFramework
    {
        $framework = $this->releaseResolver->frameworkFamily($frameworkKey);
        if ($framework === null) {
            throw new ComplianceCorpusNotFoundException("Framework not found: {$frameworkKey}.");
        }

        $framework->loadMissing('authority');

        return $framework;
    }

    public function getActiveRevision(ComplianceFrameworkRelease $release): ComplianceCorpusRevision
    {
        $revision = ComplianceCorpusRevision::query()
            ->where('framework_release_id', $release->id)
            ->where('status', CorpusRevisionStatus::Active)
            ->orderByDesc('revision_number')
            ->first();

        if ($revision === null) {
            throw new ComplianceCorpusNotFoundException(
                "No active corpus revision for release {$release->version_code}."
            );
        }

        $revision->loadMissing('importRun');

        return $revision;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(string $frameworkKey, string $releaseCode): array
    {
        $release = $this->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->getActiveRevision($release);

        $releaseId = $release->id;

        $counts = [
            'domains' => ComplianceDomain::query()->where('framework_release_id', $releaseId)->count(),
            'controls' => DB::table('compliance_controls')->where('framework_release_id', $releaseId)->count(),
            'requirements' => DB::table('compliance_requirements')->where('framework_release_id', $releaseId)->count(),
            'guidance_items' => DB::table('compliance_guidance_items as gi')
                ->join('compliance_requirements as r', 'r.id', '=', 'gi.requirement_id')
                ->where('r.framework_release_id', $releaseId)
                ->count(),
            'evidence_expectations' => DB::table('compliance_evidence_expectations as ee')
                ->join('compliance_requirements as r', 'r.id', '=', 'ee.requirement_id')
                ->where('r.framework_release_id', $releaseId)
                ->count(),
        ];

        $sourceDocuments = ComplianceSourceDocument::query()
            ->where('framework_release_id', $releaseId)
            ->orderBy('key')
            ->get();

        return [
            'framework' => $release->framework,
            'release' => $release,
            'active_revision' => $revision,
            'counts' => $counts,
            'source_documents' => $sourceDocuments,
            'pending_manual_review' => $this->batchMetadataReader->pendingManualReview($frameworkKey, $releaseCode),
        ];
    }

    public function findDomain(ComplianceFrameworkRelease $release, string $domainCode): ComplianceDomain
    {
        $domain = ComplianceDomain::query()
            ->where('framework_release_id', $release->id)
            ->where(function ($query) use ($domainCode): void {
                $query->where('code', $domainCode)
                    ->orWhere('display_code', $domainCode)
                    ->orWhere('normalized_code', $domainCode);
            })
            ->with(['sourceDocument'])
            ->first();

        if ($domain === null) {
            throw new ComplianceCorpusNotFoundException("Domain not found: {$domainCode}.");
        }

        return $domain;
    }

    public function findControl(ComplianceFrameworkRelease $release, string $controlCode): ComplianceControl
    {
        $control = ComplianceControl::query()
            ->where('framework_release_id', $release->id)
            ->where(function ($query) use ($controlCode): void {
                $query->where('code', $controlCode)
                    ->orWhere('display_code', $controlCode)
                    ->orWhere('normalized_code', $controlCode);
            })
            ->first();

        if ($control === null) {
            throw new ComplianceCorpusNotFoundException("Control not found: {$controlCode}.");
        }

        return $control;
    }

    /**
     * @return array<string, mixed>
     */
    public function getControlProfile(string $frameworkKey, string $releaseCode, string $controlCode): array
    {
        $release = $this->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->getActiveRevision($release);
        $control = $this->findControl($release, $controlCode);

        $control->load([
            'sourceDocument',
            'domain.sourceDocument',
            'requirements.sourceDocument',
            'requirements.guidanceItems.sourceDocument',
            'requirements.evidenceExpectations.sourceDocument',
            'requirements.evidenceExpectations.evidenceType',
        ]);

        return [
            'framework' => $release->framework,
            'release' => $release,
            'revision' => $revision,
            'domain' => $control->domain,
            'control' => $control,
            'requirements' => $control->requirements,
        ];
    }
}
