<?php

namespace App\Services\Compliance\Corpus;

use App\Enums\Compliance\ControlType;
use App\Enums\Compliance\GuidanceType;
use App\Enums\Compliance\ObjectiveMappingType;
use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\ComplianceEvidenceType;
use App\Models\Compliance\ComplianceFrameworkRelease;
use Illuminate\Support\Arr;

/**
 * Validates curated NCA ECC corpus import payloads before persistence.
 */
class ComplianceCorpusValidator
{
    /** @var list<string> */
    private const PLACEHOLDER_PATTERNS = [
        '/\btbd\b/i',
        '/\bsample\b/i',
        '/\blorem\b/i',
        '/\btest control\b/i',
        '/\bplaceholder\b/i',
        '/\bfake\b/i',
        '/\bexample control\b/i',
        '/\bdemo\b/i',
        '/\bxxx\b/i',
        '/\btodo\b/i',
    ];

    /** @var list<string> */
    private const FORBIDDEN_CODE_MARKERS = ['EXAMPLE', 'SAMPLE', 'TEST-', 'FAKE', 'LOREM', 'DEMO'];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $registeredSourceDocumentKeys = [];

    public function __construct(
        private readonly ComplianceSourceDocumentRegistrar $sourceDocumentRegistrar = new ComplianceSourceDocumentRegistrar(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validate(array $payload, ?ComplianceFrameworkRelease $targetRelease = null): array
    {
        $this->errors = [];
        $this->warnings = [];
        $this->registeredSourceDocumentKeys = [];

        if ($targetRelease !== null) {
            $this->registeredSourceDocumentKeys = $this->sourceDocumentRegistrar->keyMapForRelease($targetRelease);
        }

        if (! isset($payload['framework']) || ! is_array($payload['framework'])) {
            $this->errors[] = 'Missing required top-level "framework" object.';
        } else {
            $this->validateFramework($payload['framework'], $targetRelease);
        }

        $this->validateSourceDocumentReferences($payload);

        if (isset($payload['control_objectives']) && is_array($payload['control_objectives'])) {
            $this->validateControlObjectives($payload['control_objectives']);
        }

        $hasCorpusEntities = $this->payloadHasCorpusEntities($payload);

        if (isset($payload['domains']) && is_array($payload['domains'])) {
            $this->validateDomains($payload['domains'], $hasCorpusEntities);
        } elseif ($hasCorpusEntities) {
            $this->errors[] = 'domains array is required when corpus entities are present.';
        } else {
            $this->warnings[] = 'No domains provided; import will only touch framework/release metadata.';
        }

        if (isset($payload['objective_mappings']) && is_array($payload['objective_mappings'])) {
            $this->validateObjectiveMappings($payload['objective_mappings']);
        }

        return [
            'valid' => $this->errors === [],
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadHasCorpusEntities(array $payload): bool
    {
        if (! isset($payload['domains']) || ! is_array($payload['domains'])) {
            return false;
        }

        foreach ($payload['domains'] as $domain) {
            if (is_array($domain) && ($domain['controls'] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateSourceDocumentReferences(array $payload): void
    {
        $keys = $payload['source_document_keys'] ?? null;
        if (! is_array($keys) || $keys === []) {
            $this->errors[] = 'source_document_keys is required and must reference registered official source documents.';

            return;
        }

        foreach ($keys as $index => $key) {
            $key = (string) $key;
            if ($key === '') {
                $this->errors[] = "source_document_keys[{$index}] must be a non-empty string.";

                continue;
            }

            if ($this->registeredSourceDocumentKeys === []) {
                $this->errors[] = "source_document_keys[{$index}] '{$key}' cannot be verified — no source documents registered for this release. Run compliance:seed-source-documents first.";

                continue;
            }

            if (! isset($this->registeredSourceDocumentKeys[$key])) {
                $this->errors[] = "source_document_keys[{$index}] '{$key}' is not registered for this release.";
            }
        }

        $uniqueKeys = array_unique(array_map('strval', $keys));
        if (count($uniqueKeys) !== count($keys)) {
            $this->errors[] = 'source_document_keys contains duplicate entries.';
        }
    }

    /**
     * @param array<string, mixed> $framework
     */
    private function validateFramework(array $framework, ?ComplianceFrameworkRelease $targetRelease): void
    {
        foreach (['key', 'version_code'] as $field) {
            if (! filled($framework[$field] ?? null)) {
                $this->errors[] = "framework.{$field} is required.";
            }
        }

        if ($targetRelease !== null) {
            $targetRelease->loadMissing('framework');
            if (($framework['key'] ?? null) !== $targetRelease->framework?->key) {
                $this->errors[] = 'framework.key does not match target framework family.';
            }
            $releaseCode = (string) ($framework['version_code'] ?? '');
            if ($releaseCode !== $targetRelease->version_code && $releaseCode !== $targetRelease->release_code) {
                $this->errors[] = 'framework.version_code does not match target framework release.';
            }
        }

        if (isset($framework['status']) && PublicationStatus::tryFrom((string) $framework['status']) === null) {
            $this->errors[] = 'framework.status is invalid.';
        }
    }

    /**
     * @param list<array<string, mixed>> $objectives
     */
    private function validateControlObjectives(array $objectives): void
    {
        $codes = [];
        foreach ($objectives as $index => $objective) {
            $prefix = "control_objectives[{$index}]";
            if (! is_array($objective)) {
                $this->errors[] = "{$prefix} must be an object.";
                continue;
            }
            foreach (['code', 'title_en', 'title_ar'] as $field) {
                if (! filled($objective[$field] ?? null)) {
                    $this->errors[] = "{$prefix}.{$field} is required.";
                }
            }
            $this->rejectPlaceholderContent($prefix, $objective, ['title_en', 'title_ar', 'description_en', 'description_ar']);
            $code = (string) ($objective['code'] ?? '');
            $this->rejectForbiddenCodeMarkers($prefix, $code);
            if ($code !== '') {
                if (isset($codes[$code])) {
                    $this->errors[] = "Duplicate control_objective code: {$code}";
                }
                $codes[$code] = true;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $domains
     */
    private function validateDomains(array $domains, bool $strictProvenance): void
    {
        $domainCodes = [];
        $controlCodes = [];
        $requirementKeys = [];
        $domainNormalizedCodes = [];
        $controlNormalizedCodes = [];
        $requirementNormalizedCodes = [];
        $controlParentMap = [];

        foreach ($domains as $dIndex => $domain) {
            $dPrefix = "domains[{$dIndex}]";
            if (! is_array($domain)) {
                $this->errors[] = "{$dPrefix} must be an object.";
                continue;
            }

            foreach (['code', 'title_en', 'title_ar'] as $field) {
                if (! filled($domain[$field] ?? null)) {
                    $this->errors[] = "{$dPrefix}.{$field} is required.";
                }
            }

            $this->rejectPlaceholderContent($dPrefix, $domain, ['code', 'title_en', 'title_ar', 'description_en', 'description_ar']);
            $this->validateEntityProvenance($dPrefix, $domain, $strictProvenance, requireSourceDocument: false);

            $domainCode = (string) ($domain['code'] ?? '');
            $this->rejectForbiddenCodeMarkers($dPrefix, $domainCode);
            $this->validateCanonicalCode($dPrefix, $domain, $domainNormalizedCodes, 'domain');
            $this->validateOrderingAndLevel($dPrefix, $domain);
            $this->validateDeprecationState($dPrefix, $domain, 'superseded_by_domain_code');
            if ($domainCode !== '') {
                if (isset($domainCodes[$domainCode])) {
                    $this->errors[] = "Duplicate domain code: {$domainCode}";
                }
                $domainCodes[$domainCode] = true;
            }

            if (! isset($domain['controls']) || ! is_array($domain['controls'])) {
                continue;
            }

            foreach ($domain['controls'] as $cIndex => $control) {
                $cPrefix = "{$dPrefix}.controls[{$cIndex}]";
                if (! is_array($control)) {
                    $this->errors[] = "{$cPrefix} must be an object.";
                    continue;
                }

                foreach (['code', 'title_en', 'title_ar'] as $field) {
                    if (! filled($control[$field] ?? null)) {
                        $this->errors[] = "{$cPrefix}.{$field} is required.";
                    }
                }

                $this->rejectPlaceholderContent($cPrefix, $control, ['code', 'title_en', 'title_ar', 'description_en', 'description_ar']);
                $this->validateEntityProvenance($cPrefix, $control, $strictProvenance, requireSourceDocument: true);

                if (isset($control['control_type']) && ControlType::tryFrom((string) $control['control_type']) === null) {
                    $this->errors[] = "{$cPrefix}.control_type is invalid.";
                }

                $controlCode = (string) ($control['code'] ?? '');
                $this->rejectForbiddenCodeMarkers($cPrefix, $controlCode);
                $this->validateCanonicalCode($cPrefix, $control, $controlNormalizedCodes, 'control');
                $this->validateOrderingAndLevel($cPrefix, $control, levelField: 'level');
                $this->validateDeprecationState($cPrefix, $control, 'superseded_by_control_code');
                $this->validateControlSelfReference($cPrefix, $controlCode, $control);

                $parentCode = (string) ($control['parent_control_code'] ?? '');
                if ($parentCode !== '') {
                    if ($parentCode === $controlCode) {
                        $this->errors[] = "{$cPrefix}.parent_control_code must not reference itself.";
                    } else {
                        $controlParentMap[$controlCode] = $parentCode;
                    }
                }

                if ($controlCode !== '') {
                    if (isset($controlCodes[$controlCode])) {
                        $this->errors[] = "Duplicate control code: {$controlCode}";
                    }
                    $controlCodes[$controlCode] = true;
                }

                if (! isset($control['requirements']) || ! is_array($control['requirements'])) {
                    if ($strictProvenance) {
                        $this->errors[] = "{$cPrefix}.requirements is required for curated controls.";
                    }
                    continue;
                }

                foreach ($control['requirements'] as $rIndex => $requirement) {
                    $rPrefix = "{$cPrefix}.requirements[{$rIndex}]";
                    if (! is_array($requirement)) {
                        $this->errors[] = "{$rPrefix} must be an object.";
                        continue;
                    }

                    foreach (['code', 'title_en', 'title_ar', 'requirement_text_en', 'requirement_text_ar'] as $field) {
                        if (! filled($requirement[$field] ?? null)) {
                            $this->errors[] = "{$rPrefix}.{$field} is required.";
                        }
                    }

                    $this->rejectPlaceholderContent(
                        $rPrefix,
                        $requirement,
                        ['code', 'title_en', 'title_ar', 'description_en', 'description_ar', 'requirement_text_en', 'requirement_text_ar']
                    );
                    $this->validateEntityProvenance($rPrefix, $requirement, $strictProvenance, requireSourceDocument: true, requireOfficialReference: true);

                    $reqKey = "{$controlCode}::".(string) ($requirement['code'] ?? '');
                    if (str_contains($reqKey, '::') && filled($requirement['code'] ?? null)) {
                        if (isset($requirementKeys[$reqKey])) {
                            $this->errors[] = "Duplicate requirement code for control {$controlCode}: {$requirement['code']}";
                        }
                        $requirementKeys[$reqKey] = true;
                    }

                    $this->rejectForbiddenCodeMarkers($rPrefix, (string) ($requirement['code'] ?? ''));
                    $this->validateCanonicalCode($rPrefix, $requirement, $requirementNormalizedCodes, 'requirement');
                    $this->validateOrderingAndLevel($rPrefix, $requirement);
                    $this->validateDeprecationState($rPrefix, $requirement, 'superseded_by_requirement_code');

                    if (isset($requirement['guidance']) && is_array($requirement['guidance'])) {
                        $this->validateGuidance($requirement['guidance'], $rPrefix, $strictProvenance);
                    }

                    if (isset($requirement['evidence_expectations']) && is_array($requirement['evidence_expectations'])) {
                        $this->validateEvidenceExpectations($requirement['evidence_expectations'], $rPrefix, $strictProvenance);
                    }
                }
            }
        }

        $this->validateControlParentChains($controlParentMap, $controlCodes);
    }

    /**
     * @param array<string, true> $registry
     * @param array<string, mixed> $entity
     */
    private function validateCanonicalCode(string $prefix, array $entity, array &$registry, string $entityType): void
    {
        $canonical = ComplianceCodeNormalizer::displayAndNormalized($entity);
        $normalized = $canonical['normalized_code'] ?? null;
        if ($normalized === null || $normalized === '') {
            return;
        }

        $registryKey = "{$entityType}::{$normalized}";
        if (isset($registry[$registryKey])) {
            $this->errors[] = "{$prefix}: duplicate normalized_code '{$normalized}' for {$entityType}.";
        }
        $registry[$registryKey] = true;
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function validateOrderingAndLevel(string $prefix, array $entity, ?string $levelField = null): void
    {
        if (array_key_exists('sort_order', $entity) && (int) $entity['sort_order'] < 0) {
            $this->errors[] = "{$prefix}.sort_order must be zero or greater.";
        }

        if ($levelField !== null && array_key_exists($levelField, $entity) && (int) $entity[$levelField] < 1) {
            $this->errors[] = "{$prefix}.{$levelField} must be at least 1.";
        }
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function validateDeprecationState(string $prefix, array $entity, string $supersededByCodeField): void
    {
        $isDeprecated = ($entity['status'] ?? null) === PublicationStatus::Deprecated->value
            || filled($entity['deprecated_at'] ?? null);

        if (! $isDeprecated) {
            return;
        }

        $hasSuperseded = filled($entity['superseded_by_id'] ?? null)
            || filled($entity[$supersededByCodeField] ?? null)
            || filled($entity['superseded_by_code'] ?? null);

        if (! $hasSuperseded) {
            $this->errors[] = "{$prefix} is deprecated but missing superseded_by reference.";
        }
    }

    /**
     * @param array<string, mixed> $control
     */
    private function validateControlSelfReference(string $prefix, string $controlCode, array $control): void
    {
        if (filled($control['parent_control_id'] ?? null) && (int) $control['parent_control_id'] === (int) ($control['id'] ?? -1)) {
            $this->errors[] = "{$prefix}.parent_control_id must not reference itself.";
        }
    }

    /**
     * @param array<string, string> $parentMap child_code => parent_code
     * @param array<string, true> $controlCodes
     */
    private function validateControlParentChains(array $parentMap, array $controlCodes): void
    {
        foreach ($parentMap as $childCode => $parentCode) {
            if (! isset($controlCodes[$parentCode])) {
                $this->errors[] = "Control '{$childCode}' references unknown parent_control_code '{$parentCode}'.";

                continue;
            }

            $visited = [];
            $current = $childCode;
            $steps = 0;

            while (isset($parentMap[$current])) {
                if (isset($visited[$current])) {
                    $this->errors[] = "Circular parent_control_code chain detected involving '{$current}'.";
                    break;
                }
                $visited[$current] = true;
                $current = $parentMap[$current];
                $steps++;
                if ($steps > 32) {
                    $this->errors[] = "Control parent chain exceeds maximum depth (32) at '{$childCode}'.";
                    break;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function validateEntityProvenance(
        string $prefix,
        array $entity,
        bool $strict,
        bool $requireSourceDocument = false,
        bool $requireOfficialReference = false,
    ): void {
        if (! $strict) {
            return;
        }

        $sourceDocumentKey = (string) ($entity['source_document_key'] ?? '');
        if ($requireSourceDocument && $sourceDocumentKey === '') {
            $this->errors[] = "{$prefix}.source_document_key is required for curated corpus entities.";
        }

        if ($sourceDocumentKey !== '' && ! isset($this->registeredSourceDocumentKeys[$sourceDocumentKey])) {
            $this->errors[] = "{$prefix}.source_document_key '{$sourceDocumentKey}' is not registered for this release.";
        }

        $hasSourceReference = filled($entity['source_reference'] ?? null);
        $hasOfficialReference = filled($entity['official_reference'] ?? null);
        $hasSourcePage = filled($entity['source_page'] ?? null);

        if ($requireOfficialReference && ! $hasSourceReference && ! $hasOfficialReference) {
            $this->errors[] = "{$prefix} requires source_reference or official_reference from the official NCA PDF.";
        }

        if ($requireSourceDocument && ! $hasSourcePage && ! $hasSourceReference && ! $hasOfficialReference) {
            $this->errors[] = "{$prefix} requires source_page and/or source_reference tied to the official document.";
        }
    }

    /**
     * @param array<string, mixed> $entity
     * @param list<string> $fields
     */
    private function rejectPlaceholderContent(string $prefix, array $entity, array $fields): void
    {
        foreach ($fields as $field) {
            $value = (string) ($entity[$field] ?? '');
            if ($value === '') {
                continue;
            }

            foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
                if (preg_match($pattern, $value) === 1) {
                    $this->errors[] = "{$prefix}.{$field} contains placeholder or non-official text.";
                    break;
                }
            }
        }
    }

    private function rejectForbiddenCodeMarkers(string $prefix, string $code): void
    {
        if ($code === '') {
            return;
        }

        $upper = strtoupper($code);
        foreach (self::FORBIDDEN_CODE_MARKERS as $marker) {
            if (str_contains($upper, $marker)) {
                $this->errors[] = "{$prefix}.code '{$code}' contains non-production marker '{$marker}'.";
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function validateGuidance(array $items, string $parentPrefix, bool $strictProvenance): void
    {
        $codes = [];
        foreach ($items as $index => $item) {
            $prefix = "{$parentPrefix}.guidance[{$index}]";
            if (! is_array($item)) {
                $this->errors[] = "{$prefix} must be an object.";
                continue;
            }
            foreach (['code', 'guidance_en', 'guidance_ar'] as $field) {
                if (! filled($item[$field] ?? null)) {
                    $this->errors[] = "{$prefix}.{$field} is required.";
                }
            }
            $this->rejectPlaceholderContent($prefix, $item, ['code', 'guidance_en', 'guidance_ar']);
            $this->validateEntityProvenance($prefix, $item, $strictProvenance, requireSourceDocument: false, requireOfficialReference: true);
            if (isset($item['guidance_type']) && GuidanceType::tryFrom((string) $item['guidance_type']) === null) {
                $this->errors[] = "{$prefix}.guidance_type is invalid.";
            }
            $code = (string) ($item['code'] ?? '');
            if ($code !== '' && isset($codes[$code])) {
                $this->errors[] = "{$prefix}: duplicate guidance code {$code}";
            }
            $codes[$code] = true;
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function validateEvidenceExpectations(array $items, string $parentPrefix, bool $strictProvenance): void
    {
        $knownTypes = ComplianceEvidenceType::query()->pluck('key')->all();
        $codes = [];

        foreach ($items as $index => $item) {
            $prefix = "{$parentPrefix}.evidence_expectations[{$index}]";
            if (! is_array($item)) {
                $this->errors[] = "{$prefix} must be an object.";
                continue;
            }
            foreach (['code', 'evidence_type_key'] as $field) {
                if (! filled($item[$field] ?? null)) {
                    $this->errors[] = "{$prefix}.{$field} is required.";
                }
            }
            $this->rejectPlaceholderContent($prefix, $item, ['code', 'title_en', 'title_ar', 'description_en', 'description_ar']);
            $this->validateEntityProvenance($prefix, $item, $strictProvenance, requireSourceDocument: false, requireOfficialReference: false);
            $typeKey = (string) ($item['evidence_type_key'] ?? '');
            if ($typeKey !== '' && $knownTypes !== [] && ! in_array($typeKey, $knownTypes, true)) {
                $this->errors[] = "{$prefix}: unknown evidence_type_key '{$typeKey}'. Seed evidence types first.";
            }
            $code = (string) ($item['code'] ?? '');
            if ($code !== '' && isset($codes[$code])) {
                $this->errors[] = "{$prefix}: duplicate evidence expectation code {$code}";
            }
            $codes[$code] = true;
        }
    }

    /**
     * @param list<array<string, mixed>> $mappings
     */
    private function validateObjectiveMappings(array $mappings): void
    {
        foreach ($mappings as $index => $mapping) {
            $prefix = "objective_mappings[{$index}]";
            if (! is_array($mapping)) {
                $this->errors[] = "{$prefix} must be an object.";
                continue;
            }
            foreach (['control_objective_code', 'control_code'] as $field) {
                if (! filled($mapping[$field] ?? null)) {
                    $this->errors[] = "{$prefix}.{$field} is required.";
                }
            }
            if (isset($mapping['mapping_type']) && ObjectiveMappingType::tryFrom((string) $mapping['mapping_type']) === null) {
                $this->errors[] = "{$prefix}.mapping_type is invalid.";
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function contentHash(array $payload): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($payload), JSON_THROW_ON_ERROR));
    }
}
