<?php

namespace App\Services\Compliance\Rag\Providers;

use App\Contracts\Compliance\Retrieval\VectorRetrievalProviderInterface;
use App\DataTransferObjects\Ai\AiEmbeddingsRequest;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalChunk;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalQuery;
use App\Services\Ai\AiProviderRegistry;
use RuntimeException;

/**
 * OpenAI-backed vector retrieval provider (QCIF Sprint 17) — METADATA-ONLY mode.
 *
 * This is the first concrete implementation behind {@see VectorRetrievalProviderInterface}. It uses
 * OpenAI embeddings via the existing {@see AiProviderRegistry} (so NO direct OpenAI HTTP calls live
 * outside provider classes). Because no real vector store (e.g. pgvector) is wired in this sprint,
 * it stores embedding METADATA only and NEVER fabricates vector similarity: `search()` returns no
 * semantic candidates, so the hybrid layer falls back to deterministic retrieval. Everything is
 * feature-flagged and OFF by default.
 */
class OpenAiVectorRetrievalProvider implements VectorRetrievalProviderInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly AiProviderRegistry $registry,
    ) {}

    public function key(): string
    {
        return 'openai';
    }

    /**
     * Compute embeddings (when enabled) to validate the runtime; persist NOTHING here (the indexer
     * persists chunk metadata). Returns a metadata-only report. No vector store ⇒ no vector_id.
     *
     * @param  list<RetrievalChunk>  $chunks
     * @return array<string, mixed>
     */
    public function index(array $chunks): array
    {
        if (! $this->embeddingsEnabled()) {
            return ['provider' => 'openai', 'mode' => 'metadata_only', 'embedded' => 0, 'note' => 'embeddings_disabled'];
        }

        $texts = [];
        foreach ($chunks as $chunk) {
            $texts[] = (string) ($chunk->textEn ?? $chunk->entityCode ?? '');
        }
        $texts = array_values(array_filter($texts, static fn ($t) => $t !== ''));

        if ($texts === []) {
            return ['provider' => 'openai', 'mode' => 'metadata_only', 'embedded' => 0, 'note' => 'no_text'];
        }

        $response = $this->embed($texts);

        return [
            'provider' => 'openai',
            'mode' => 'embedded_metadata_only',
            'embedded' => count($response->vectors),
            'embedding_model' => $response->model,
            'dimensions' => isset($response->vectors[0]) ? count($response->vectors[0]) : null,
            // No external vector store in this sprint ⇒ no vector ids to reference.
            'vector_ids' => [],
        ];
    }

    /**
     * No vector store ⇒ NO semantic candidates and NO faked similarity. Returns []. When embeddings
     * are enabled we validate provider reachability (embedding the query); a failure is surfaced as
     * an exception so the hybrid layer can fall back with a `vector_provider_unavailable` warning.
     *
     * @return list<RetrievalChunk>
     */
    public function search(RetrievalQuery $query): array
    {
        if ($this->embeddingsEnabled()) {
            // Validate the runtime; the resulting vector is intentionally discarded (no store).
            $this->embed([$query->query !== '' ? $query->query : ($query->code ?? 'compliance')]);
        }

        return [];
    }

    /**
     * @param  list<string>  $chunkUuids
     * @return array<string, mixed>
     */
    public function delete(array $chunkUuids): array
    {
        // No external vector store to delete from in metadata-only mode.
        return ['provider' => 'openai', 'mode' => 'metadata_only', 'deleted_external' => 0, 'requested' => count($chunkUuids)];
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        if (! (bool) config('ai.rag.enabled', false)) {
            return ['provider' => 'openai', 'status' => 'disabled', 'semantic_search' => false];
        }

        if (! $this->embeddingsEnabled()) {
            return ['provider' => 'openai', 'status' => 'metadata_only', 'semantic_search' => false, 'embeddings' => false];
        }

        try {
            $health = $this->registry->get($this->aiProviderKey())->health();

            return [
                'provider' => 'openai',
                'status' => $health->ok ? 'ok' : 'degraded',
                'semantic_search' => false,
                'embeddings' => true,
                'ai_provider' => $health->toArray(),
            ];
        } catch (\Throwable $e) {
            return ['provider' => 'openai', 'status' => 'down', 'semantic_search' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function supportedCapabilities(): array
    {
        return [
            'mode' => 'metadata_only',
            'vector_store' => 'none',
            'semantic_search' => false,
            'embeddings' => $this->embeddingsEnabled(),
            'bilingual' => true,
            'fakes_similarity' => false,
        ];
    }

    /**
     * @param  list<string>  $texts
     */
    private function embed(array $texts): \App\DataTransferObjects\Ai\AiEmbeddingsResponse
    {
        try {
            return $this->registry->get($this->aiProviderKey())->embeddings(
                new AiEmbeddingsRequest($texts, $this->embeddingsModel()),
            );
        } catch (\Throwable $e) {
            throw new RuntimeException('Vector provider embeddings failed: '.$e->getMessage(), 0, $e);
        }
    }

    private function embeddingsEnabled(): bool
    {
        return (bool) config('ai.rag.embeddings_enabled', false);
    }

    private function aiProviderKey(): string
    {
        $key = $this->config['ai_provider'] ?? 'openai';

        return is_string($key) && $key !== '' ? $key : 'openai';
    }

    private function embeddingsModel(): ?string
    {
        $model = config('ai.rag.embeddings_model');

        return is_string($model) && $model !== '' ? $model : null;
    }
}
