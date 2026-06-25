<?php

namespace App\Services\Compliance\Retrieval;

use App\DataTransferObjects\Ai\AiSkillResponse;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalChunk;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalCitation;

/**
 * Merges and de-duplicates citations for a retrieval result (QCIF Sprint 15).
 *
 * It combines (a) the corpus citations the skills already produced and (b) the per-chunk citations
 * derived from chunk provenance, into one de-duplicated, deterministic list. Pure data — no AI/DB.
 */
class ComplianceRetrievalCitationMerger
{
    /**
     * @param  list<AiSkillResponse>  $responses
     * @param  list<RetrievalChunk>  $chunks
     * @return list<RetrievalCitation>
     */
    public function merge(array $responses, array $chunks): array
    {
        $merged = [];
        $seen = [];

        $add = function (RetrievalCitation $citation) use (&$merged, &$seen): void {
            $key = $citation->dedupeKey();
            if ($key === '|||') {
                return;
            }
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $merged[] = $citation;
            }
        };

        foreach ($responses as $response) {
            if (! $response->success || $response->result === null) {
                continue;
            }
            foreach ($response->result->citations as $citation) {
                if (is_array($citation)) {
                    $add(RetrievalCitation::fromArray($citation));
                }
            }
        }

        foreach ($chunks as $chunk) {
            foreach ($chunk->citations as $citation) {
                $add($citation);
            }
        }

        return $merged;
    }
}
