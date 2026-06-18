<?php

namespace App\Services\Compliance\Corpus;

use App\Enums\Compliance\ControlType;
use App\Enums\Compliance\GuidanceType;
use App\Enums\Compliance\ObjectiveMappingType;
use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\ComplianceEvidenceType;
use App\Models\Compliance\ComplianceFramework;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Validates curated NCA ECC corpus import payloads before persistence.
 */
class ComplianceCorpusValidator
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param array<string, mixed> $payload
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validate(array $payload, ?ComplianceFramework $targetFramework = null): array
    {
        $this->errors = [];
        $this->warnings = [];

        if (! isset($payload['framework']) || ! is_array($payload['framework'])) {
            $this->errors[] = 'Missing required top-level "framework" object.';
        } else {
            $this->validateFramework($payload['framework'], $targetFramework);
        }

        if (isset($payload['control_objectives']) && is_array($payload['control_objectives'])) {
            $this->validateControlObjectives($payload['control_objectives']);
        }

        if (isset($payload['domains']) && is_array($payload['domains'])) {
            $this->validateDomains($payload['domains']);
        } elseif (! isset($payload['framework'])) {
            // framework error already recorded
        } else {
            $this->warnings[] = 'No domains provided; import will only touch framework metadata.';
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
     * @param array<string, mixed> $framework
     */
    private function validateFramework(array $framework, ?ComplianceFramework $targetFramework): void
    {
        foreach (['key', 'version_code', 'code', 'title_en', 'title_ar', 'authority'] as $field) {
            if (! filled($framework[$field] ?? null)) {
                $this->errors[] = "framework.{$field} is required.";
            }
        }

        if ($targetFramework !== null) {
            if (($framework['key'] ?? null) !== $targetFramework->key) {
                $this->errors[] = 'framework.key does not match target framework.';
            }
            if (($framework['version_code'] ?? null) !== $targetFramework->version_code) {
                $this->errors[] = 'framework.version_code does not match target framework.';
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
            $code = (string) ($objective['code'] ?? '');
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
    private function validateDomains(array $domains): void
    {
        $domainCodes = [];
        $controlCodes = [];
        $requirementKeys = [];

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

            $domainCode = (string) ($domain['code'] ?? '');
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

                if (isset($control['control_type']) && ControlType::tryFrom((string) $control['control_type']) === null) {
                    $this->errors[] = "{$cPrefix}.control_type is invalid.";
                }

                $controlCode = (string) ($control['code'] ?? '');
                if ($controlCode !== '') {
                    if (isset($controlCodes[$controlCode])) {
                        $this->errors[] = "Duplicate control code: {$controlCode}";
                    }
                    $controlCodes[$controlCode] = true;
                }

                if (! isset($control['requirements']) || ! is_array($control['requirements'])) {
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

                    $reqKey = "{$controlCode}::".(string) ($requirement['code'] ?? '');
                    if (str_contains($reqKey, '::') && filled($requirement['code'] ?? null)) {
                        if (isset($requirementKeys[$reqKey])) {
                            $this->errors[] = "Duplicate requirement code for control {$controlCode}: {$requirement['code']}";
                        }
                        $requirementKeys[$reqKey] = true;
                    }

                    if (isset($requirement['guidance']) && is_array($requirement['guidance'])) {
                        $this->validateGuidance($requirement['guidance'], $rPrefix);
                    }

                    if (isset($requirement['evidence_expectations']) && is_array($requirement['evidence_expectations'])) {
                        $this->validateEvidenceExpectations($requirement['evidence_expectations'], $rPrefix);
                    }
                }
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function validateGuidance(array $items, string $parentPrefix): void
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
    private function validateEvidenceExpectations(array $items, string $parentPrefix): void
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
