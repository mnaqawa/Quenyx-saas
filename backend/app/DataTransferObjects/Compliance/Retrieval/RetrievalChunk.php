<?php

namespace App\DataTransferObjects\Compliance\Retrieval;

/**
 * A retrieval chunk (QCIF Sprint 15) — the future RAG unit of context.
 *
 * Every chunk is a self-contained, cited, bilingual unit derived deterministically from corpus /
 * graph / tenant context. UUID-only (the chunk uuid is deterministic; entity_uuid is the corpus
 * entity). NO embeddings, NO vectors, NO numeric ids. Pure data.
 */
final readonly class RetrievalChunk
{
    /**
     * @param  list<RetrievalCitation>  $citations
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $uuid,
        public string $chunkType,
        public ?string $entityType,
        public ?string $entityUuid,
        public ?string $entityCode,
        public ?string $textEn,
        public ?string $textAr,
        public ?string $sourceDocumentKey,
        public ?string $officialReference,
        public ?string $sourcePage,
        public ?string $revisionUuid,
        public array $citations = [],
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'chunk_type' => $this->chunkType,
            'entity_type' => $this->entityType,
            'entity_uuid' => $this->entityUuid,
            'entity_code' => $this->entityCode,
            'text_en' => $this->textEn,
            'text_ar' => $this->textAr,
            'source_document_key' => $this->sourceDocumentKey,
            'official_reference' => $this->officialReference,
            'source_page' => $this->sourcePage,
            'revision_uuid' => $this->revisionUuid,
            'citations' => array_map(fn (RetrievalCitation $c) => $c->toArray(), $this->citations),
            'metadata' => $this->metadata,
        ];
    }
}
