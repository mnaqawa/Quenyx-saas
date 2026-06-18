<?php

namespace App\Services\Compliance\Corpus;

use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceFrameworkRelease;

class ComplianceFrameworkReleaseResolver
{
    public function resolve(string $frameworkKey, string $releaseCode): ?ComplianceFrameworkRelease
    {
        return ComplianceFrameworkRelease::query()
            ->whereHas('framework', fn ($q) => $q->where('key', $frameworkKey))
            ->where(function ($query) use ($releaseCode): void {
                $query->where('version_code', $releaseCode)
                    ->orWhere('release_code', $releaseCode);
            })
            ->first();
    }

    public function resolveOrFail(string $frameworkKey, string $releaseCode): ComplianceFrameworkRelease
    {
        $release = $this->resolve($frameworkKey, $releaseCode);
        if ($release === null) {
            throw ComplianceCorpusImportException::validationFailed([
                "Framework release not found for framework={$frameworkKey}, release={$releaseCode}. Run ComplianceCorpusSeeder first.",
            ]);
        }

        return $release;
    }

    public function frameworkFamily(string $frameworkKey): ?ComplianceFramework
    {
        return ComplianceFramework::query()->where('key', $frameworkKey)->first();
    }
}
