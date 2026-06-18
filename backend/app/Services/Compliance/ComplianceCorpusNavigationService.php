<?php

namespace App\Services\Compliance;

use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\ComplianceRequirement;
use Illuminate\Database\Eloquent\Collection;

class ComplianceCorpusNavigationService
{
    public function __construct(
        private readonly ComplianceCorpusQueryService $queryService = new ComplianceCorpusQueryService(),
    ) {}

    /**
     * @return Collection<int, ComplianceFramework>
     */
    public function listFrameworks(): Collection
    {
        return ComplianceFramework::query()
            ->with('authority')
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();
    }

    /**
     * @return Collection<int, ComplianceFrameworkRelease>
     */
    public function listReleases(string $frameworkKey): Collection
    {
        $framework = $this->queryService->resolveReleaseFramework($frameworkKey);

        return ComplianceFrameworkRelease::query()
            ->where('framework_id', $framework->id)
            ->with('framework')
            ->orderByDesc('effective_date')
            ->orderByDesc('version_code')
            ->get();
    }

    /**
     * @return Collection<int, ComplianceDomain>
     */
    public function listDomains(string $frameworkKey, string $releaseCode): Collection
    {
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);

        return ComplianceDomain::query()
            ->where('framework_release_id', $release->id)
            ->with('sourceDocument')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, ComplianceControl>
     */
    public function listControlsByDomain(string $frameworkKey, string $releaseCode, string $domainCode): Collection
    {
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $domain = $this->queryService->findDomain($release, $domainCode);

        return ComplianceControl::query()
            ->where('framework_release_id', $release->id)
            ->where('domain_id', $domain->id)
            ->with('sourceDocument')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, ComplianceRequirement>
     */
    public function listRequirementsByControl(string $frameworkKey, string $releaseCode, string $controlCode): Collection
    {
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $control = $this->queryService->findControl($release, $controlCode);

        return ComplianceRequirement::query()
            ->where('framework_release_id', $release->id)
            ->where('control_id', $control->id)
            ->with('sourceDocument')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * @return array{domain: ComplianceDomain, controls: Collection<int, ComplianceControl>}
     */
    public function getDomainWithControls(string $frameworkKey, string $releaseCode, string $domainCode): array
    {
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $domain = $this->queryService->findDomain($release, $domainCode);
        $controls = $this->listControlsByDomain($frameworkKey, $releaseCode, $domainCode);

        return [
            'domain' => $domain,
            'controls' => $controls,
        ];
    }
}
