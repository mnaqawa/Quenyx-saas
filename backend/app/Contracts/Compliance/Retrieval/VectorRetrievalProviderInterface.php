<?php

namespace App\Contracts\Compliance\Retrieval;

use App\DataTransferObjects\Compliance\Retrieval\RetrievalChunk;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalQuery;

/**
 * Future vector-retrieval provider contract (QCIF Sprint 15) — INTERFACE ONLY.
 *
 * This declares the seam a future RAG implementation would plug into (Qdrant, pgvector, OpenAI File
 * Search, etc.). Sprint 15 ships NO implementation, NO embeddings, and NO vector database. The
 * deterministic retrieval layer (ComplianceRetrievalService) works entirely without it; a provider
 * would later be resolved via a registry, exactly like the AI Provider Registry pattern.
 */
interface VectorRetrievalProviderInterface
{
    /**
     * Provider key (e.g. 'qdrant', 'pgvector', 'openai_file_search').
     */
    public function key(): string;

    /**
     * Index/upsert chunks into the vector store.
     *
     * @param  list<RetrievalChunk>  $chunks
     * @return array<string, mixed>  index report (counts, ids upserted) — UUID-only
     */
    public function index(array $chunks): array;

    /**
     * Semantic search for chunks relevant to a query.
     *
     * @return list<RetrievalChunk>
     */
    public function search(RetrievalQuery $query): array;

    /**
     * Delete chunks by their UUIDs.
     *
     * @param  list<string>  $chunkUuids
     * @return array<string, mixed>  deletion report
     */
    public function delete(array $chunkUuids): array;

    /**
     * Provider health/readiness.
     *
     * @return array<string, mixed>  e.g. ['status' => 'ok'|'degraded'|'down', ...]
     */
    public function health(): array;

    /**
     * Capabilities this provider supports (e.g. hybrid search, filtering, bilingual).
     *
     * @return array<string, mixed>
     */
    public function supportedCapabilities(): array;
}
