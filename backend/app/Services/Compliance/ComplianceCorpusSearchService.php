<?php

namespace App\Services\Compliance;

use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceRequirement;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class ComplianceCorpusSearchService
{
    private const MIN_QUERY_LENGTH = 2;
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly ComplianceCorpusQueryService $queryService = new ComplianceCorpusQueryService(),
    ) {}

    /**
     * @return array{
     *     query: string,
     *     limit: int,
     *     domains: Collection<int, ComplianceDomain>,
     *     controls: Collection<int, ComplianceControl>,
     *     requirements: Collection<int, ComplianceRequirement>
     * }
     */
    public function search(string $frameworkKey, string $releaseCode, string $query, ?int $limit = null): array
    {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            throw new InvalidArgumentException('Search query must be at least '.self::MIN_QUERY_LENGTH.' characters.');
        }

        $limit = min(max($limit ?? self::DEFAULT_LIMIT, 1), self::MAX_LIMIT);
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $releaseId = $release->id;
        $like = '%'.$this->escapeLike($query).'%';

        $domains = ComplianceDomain::query()
            ->where('framework_release_id', $releaseId)
            ->where(function ($builder) use ($like, $query): void {
                $builder->where('code', 'like', $like)
                    ->orWhere('display_code', 'like', $like)
                    ->orWhere('normalized_code', 'like', $like)
                    ->orWhere('title_en', 'like', $like)
                    ->orWhere('title_ar', 'like', $like)
                    ->orWhere('code', $query)
                    ->orWhere('display_code', $query)
                    ->orWhere('normalized_code', $query);
            })
            ->with('sourceDocument')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();

        $controls = ComplianceControl::query()
            ->where('framework_release_id', $releaseId)
            ->where(function ($builder) use ($like, $query): void {
                $builder->where('code', 'like', $like)
                    ->orWhere('display_code', 'like', $like)
                    ->orWhere('normalized_code', 'like', $like)
                    ->orWhere('title_en', 'like', $like)
                    ->orWhere('title_ar', 'like', $like);

                $builder->orWhere('code', $query)
                    ->orWhere('display_code', $query)
                    ->orWhere('normalized_code', $query);
            })
            ->with(['sourceDocument', 'domain'])
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();

        $requirements = ComplianceRequirement::query()
            ->where('framework_release_id', $releaseId)
            ->where(function ($builder) use ($like, $query): void {
                $builder->where('code', 'like', $like)
                    ->orWhere('display_code', 'like', $like)
                    ->orWhere('normalized_code', 'like', $like)
                    ->orWhere('title_en', 'like', $like)
                    ->orWhere('title_ar', 'like', $like)
                    ->orWhere('requirement_text_en', 'like', $like)
                    ->orWhere('requirement_text_ar', 'like', $like);

                $builder->orWhere('code', $query)
                    ->orWhere('display_code', $query)
                    ->orWhere('normalized_code', $query);
            })
            ->with(['sourceDocument', 'control'])
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();

        return [
            'query' => $query,
            'limit' => $limit,
            'domains' => $domains,
            'controls' => $controls,
            'requirements' => $requirements,
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
