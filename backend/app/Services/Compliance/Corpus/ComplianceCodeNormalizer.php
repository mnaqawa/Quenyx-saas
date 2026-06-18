<?php

namespace App\Services\Compliance\Corpus;

/**
 * Derives stable normalized codes from official display codes.
 *
 * Rules: lowercase; replace . * / - and spaces with underscore; collapse repeats.
 * Original `code` field is never mutated.
 */
final class ComplianceCodeNormalizer
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

    public static function displayAndNormalized(array $row, string $codeField = 'code'): array
    {
        $code = (string) ($row[$codeField] ?? '');
        $displayCode = filled($row['display_code'] ?? null)
            ? (string) $row['display_code']
            : $code;

        $normalizedCode = filled($row['normalized_code'] ?? null)
            ? (string) $row['normalized_code']
            : self::normalize($displayCode);

        return [
            'display_code' => $displayCode !== '' ? $displayCode : null,
            'normalized_code' => $normalizedCode,
        ];
    }
}
