<?php

declare(strict_types=1);

namespace Qcif\Ecc2024;

final class CorpusBatchBuilder
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[\.\*\/\-\s]+/', '_', $normalized) ?? $normalized;
        $normalized = preg_replace('/_+/', '_', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_');

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function control(
        string $code,
        string $titleEn,
        string $titleAr,
        string $textEn,
        string $textAr,
        string $subdomain,
        int $sort,
        string $page,
        int $domainNumber,
    ): array {
        return [
            'code' => $code,
            'display_code' => $code,
            'normalized_code' => self::normalize($code),
            'title_en' => $titleEn,
            'title_ar' => $titleAr,
            'description_en' => $textEn,
            'description_ar' => $textAr,
            'source_document_key' => 'nca-ecc-2-2024-en',
            'source_reference' => "NCA ECC-2:2024 EN - Domain {$domainNumber} - Control {$code}",
            'official_reference' => "ECC-2:2024:{$domainNumber}:{$code}",
            'source_page' => $page,
            'sort_order' => $sort,
            'level' => 1,
            'metadata' => ['subdomain' => $subdomain],
            'requirements' => [[
                'code' => $code,
                'display_code' => $code,
                'normalized_code' => self::normalize($code),
                'title_en' => $titleEn,
                'title_ar' => $titleAr,
                'requirement_text_en' => $textEn,
                'requirement_text_ar' => $textAr,
                'source_document_key' => 'nca-ecc-2-2024-en',
                'source_reference' => "NCA ECC-2:2024 EN - Domain {$domainNumber} - Control {$code}",
                'official_reference' => "ECC-2:2024:{$domainNumber}:{$code}",
                'source_page' => $page,
                'metadata' => ['subdomain' => $subdomain],
            ]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $controls
     * @param list<string> $subdomains
     * @param array<string, mixed> $extraMetadata
     * @return array<string, mixed>
     */
    public static function domainBatch(
        string $slug,
        string $sprint,
        int $domainNumber,
        string $domainCode,
        string $displayCode,
        string $titleEn,
        string $titleAr,
        string $descriptionEn,
        string $descriptionAr,
        string $sourcePage,
        array $controls,
        array $subdomains,
        array $extraMetadata = [],
    ): array {
        return [
            '$schema' => 'quenyx/qcif/domain-batch/v1.0',
            'status' => 'validated',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'notes' => "Sprint {$sprint} - curated from official NCA ECC-2:2024 EN/AR publications. Pending human approval before production import.",
            'metadata' => array_merge([
                'curation_sprint' => $sprint,
                'domain_slug' => $slug,
                'source_publication' => 'ECC-2:2024',
                'control_count' => count($controls),
                'requirement_count' => count($controls),
            ], $extraMetadata),
            'domain' => [
                'code' => $domainCode,
                'display_code' => $displayCode,
                'normalized_code' => self::normalize($displayCode),
                'title_en' => $titleEn,
                'title_ar' => $titleAr,
                'description_en' => $descriptionEn,
                'description_ar' => $descriptionAr,
                'source_document_key' => 'nca-ecc-2-2024-en',
                'source_reference' => "NCA ECC-2:2024 EN - Domain {$domainNumber} {$titleEn}",
                'official_reference' => "ECC-2:2024:{$domainNumber}",
                'source_page' => $sourcePage,
                'sort_order' => 1,
                'metadata' => [
                    'subdomain_count' => count($subdomains),
                    'official_subdomains' => $subdomains,
                ],
                'controls' => $controls,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $batch
     */
    public static function writeDomainBatch(string $path, array $batch): void
    {
        file_put_contents(
            $path,
            json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"
        );
    }
}
