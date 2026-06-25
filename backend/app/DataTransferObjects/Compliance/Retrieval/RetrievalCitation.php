<?php

namespace App\DataTransferObjects\Compliance\Retrieval;

/**
 * A retrieval citation (QCIF Sprint 15). Mirrors the canonical corpus citation shape: it links a
 * chunk/entity to its official provenance. UUID-only — never a numeric id. Pure data.
 */
final readonly class RetrievalCitation
{
    public function __construct(
        public ?string $sourceDocumentKey,
        public ?string $sourceTitleEn,
        public ?string $sourceTitleAr,
        public ?string $officialReference,
        public ?string $sourceReference,
        public ?string $sourcePage,
        public ?string $entityUuid,
        public ?string $entityType,
        public ?string $entityCode,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceDocumentKey: self::str($data, 'source_document_key'),
            sourceTitleEn: self::str($data, 'source_title_en'),
            sourceTitleAr: self::str($data, 'source_title_ar'),
            officialReference: self::str($data, 'official_reference'),
            sourceReference: self::str($data, 'source_reference'),
            sourcePage: self::str($data, 'source_page'),
            entityUuid: self::str($data, 'entity_uuid'),
            entityType: self::str($data, 'entity_type'),
            entityCode: self::str($data, 'entity_code'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_document_key' => $this->sourceDocumentKey,
            'source_title_en' => $this->sourceTitleEn,
            'source_title_ar' => $this->sourceTitleAr,
            'official_reference' => $this->officialReference,
            'source_reference' => $this->sourceReference,
            'source_page' => $this->sourcePage,
            'entity_uuid' => $this->entityUuid,
            'entity_type' => $this->entityType,
            'entity_code' => $this->entityCode,
        ];
    }

    public function dedupeKey(): string
    {
        return implode('|', [
            (string) $this->entityUuid,
            (string) $this->sourceDocumentKey,
            (string) $this->officialReference,
            (string) $this->entityCode,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
