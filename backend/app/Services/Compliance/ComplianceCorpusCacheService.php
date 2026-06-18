<?php

namespace App\Services\Compliance;

use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceFrameworkRelease;
use Illuminate\Support\Facades\Cache;

class ComplianceCorpusCacheService
{
    public function __construct(
        private readonly ComplianceCorpusQueryService $queryService = new ComplianceCorpusQueryService(),
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('compliance.corpus.cache_enabled', true);
    }

    public function ttl(): int
    {
        return max(1, (int) config('compliance.corpus.cache_ttl', 3600));
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $frameworkKey, string $releaseCode, string $segment, callable $callback): mixed
    {
        if (! $this->isEnabled()) {
            return $callback();
        }

        $revisionKey = $this->activeRevisionKey($frameworkKey, $releaseCode);
        $cacheKey = "qcif:corpus:{$revisionKey}:{$segment}";

        return Cache::remember($cacheKey, $this->ttl(), $callback);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function rememberStatic(string $segment, callable $callback): mixed
    {
        if (! $this->isEnabled()) {
            return $callback();
        }

        $cacheKey = "qcif:corpus:static:{$segment}";

        return Cache::remember($cacheKey, $this->ttl(), $callback);
    }

    public function activeRevisionKey(string $frameworkKey, string $releaseCode): string
    {
        $staticKey = "qcif:corpus:rev-pointer:{$frameworkKey}:{$releaseCode}";

        if (! $this->isEnabled()) {
            return $this->buildRevisionKey($frameworkKey, $releaseCode);
        }

        return Cache::remember($staticKey, min($this->ttl(), 300), function () use ($frameworkKey, $releaseCode): string {
            return $this->buildRevisionKey($frameworkKey, $releaseCode);
        });
    }

    private function buildRevisionKey(string $frameworkKey, string $releaseCode): string
    {
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->queryService->getActiveRevision($release);

        return "{$frameworkKey}:{$releaseCode}:{$revision->uuid}";
    }

    public function forgetRelease(string $frameworkKey, string $releaseCode): void
    {
        Cache::forget("qcif:corpus:rev-pointer:{$frameworkKey}:{$releaseCode}");
    }

    /**
     * Call after a new active revision is created to drop the revision pointer cache.
     */
    public function onRevisionActivated(ComplianceFrameworkRelease $release, ComplianceCorpusRevision $revision): void
    {
        $frameworkKey = (string) ($release->framework?->key ?? '');
        $releaseCode = (string) $release->version_code;
        if ($frameworkKey !== '') {
            $this->forgetRelease($frameworkKey, $releaseCode);
        }
    }
}
