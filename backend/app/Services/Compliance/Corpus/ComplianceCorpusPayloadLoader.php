<?php

namespace App\Services\Compliance\Corpus;

use InvalidArgumentException;

class ComplianceCorpusPayloadLoader
{
    /**
     * @return array<string, mixed>
     */
    public function load(string $path, string $format): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException("Import file not readable: {$path}");
        }

        return match (strtolower($format)) {
            'json' => $this->loadJson($path),
            'csv' => $this->loadCsv($path),
            default => throw new InvalidArgumentException("Unsupported import format: {$format}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InvalidArgumentException("Unable to read file: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Invalid JSON corpus payload.');
        }

        return $decoded;
    }

    /**
     * CSV import converts flat curated sheets into the canonical JSON shape.
     *
     * Expected columns (header row required):
     * entity_type, parent_ref, code, title_en, title_ar, description_en, description_ar,
     * requirement_text_en, requirement_text_ar, guidance_en, guidance_ar,
     * evidence_type_key, control_objective_code, control_type, sort_order, source_reference
     *
     * entity_type: domain | control | requirement | guidance | evidence_expectation | control_objective
     *
     * @return array<string, mixed>
     */
    private function loadCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException("Unable to open CSV: {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new InvalidArgumentException('CSV file is empty.');
        }

        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
        $payload = [
            'framework' => null,
            'control_objectives' => [],
            'domains' => [],
            'objective_mappings' => [],
        ];

        $domains = [];
        $controls = [];
        $requirements = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            /** @var array<string, string|null> $record */
            $record = [];
            foreach ($header as $index => $column) {
                $record[$column] = isset($row[$index]) ? trim((string) $row[$index]) : null;
            }

            $type = $record['entity_type'] ?? null;
            if ($type === null || $type === '') {
                continue;
            }

            match ($type) {
                'control_objective' => $payload['control_objectives'][] = $this->csvObjectiveRow($record),
                'domain' => $domains[$record['code'] ?? ''] = $this->csvDomainRow($record),
                'control' => $this->csvControlRow($record, $controls),
                'requirement' => $this->csvRequirementRow($record, $requirements),
                default => null,
            };
        }

        fclose($handle);

        foreach ($controls as $domainCode => $domainControls) {
            if (! isset($domains[$domainCode])) {
                throw new InvalidArgumentException("CSV control references unknown domain: {$domainCode}");
            }
            foreach ($domainControls as $controlCode => $control) {
                $control['requirements'] = array_values($requirements[$controlCode] ?? []);
                $domains[$domainCode]['controls'][] = $control;
            }
        }

        $payload['domains'] = array_values($domains);

        return $payload;
    }

    /**
     * @param array<string, string|null> $record
     * @return array<string, mixed>
     */
    private function csvDomainRow(array $record): array
    {
        return [
            'code' => $record['code'],
            'parent_code' => $record['parent_ref'] ?: null,
            'title_en' => $record['title_en'],
            'title_ar' => $record['title_ar'],
            'description_en' => $record['description_en'],
            'description_ar' => $record['description_ar'],
            'sort_order' => (int) ($record['sort_order'] ?? 0),
            'source_reference' => $record['source_reference'],
            'controls' => [],
        ];
    }

    /**
     * @param array<string, string|null> $record
     * @param array<string, array<string, mixed>> $controls
     */
    private function csvControlRow(array $record, array &$controls): void
    {
        $domainCode = (string) ($record['parent_ref'] ?? '');
        $controls[$domainCode][(string) $record['code']] = [
            'code' => $record['code'],
            'control_objective_code' => $record['control_objective_code'] ?: null,
            'control_type' => $record['control_type'] ?: null,
            'title_en' => $record['title_en'],
            'title_ar' => $record['title_ar'],
            'description_en' => $record['description_en'],
            'description_ar' => $record['description_ar'],
            'sort_order' => (int) ($record['sort_order'] ?? 0),
            'source_reference' => $record['source_reference'],
            'requirements' => [],
        ];
    }

    /**
     * @param array<string, string|null> $record
     * @param array<string, array<string, array<string, mixed>>> $requirements
     */
    private function csvRequirementRow(array $record, array &$requirements): void
    {
        $controlCode = (string) ($record['parent_ref'] ?? '');
        $requirements[$controlCode][(string) $record['code']] = [
            'code' => $record['code'],
            'title_en' => $record['title_en'],
            'title_ar' => $record['title_ar'],
            'description_en' => $record['description_en'],
            'description_ar' => $record['description_ar'],
            'requirement_text_en' => $record['requirement_text_en'],
            'requirement_text_ar' => $record['requirement_text_ar'],
            'sort_order' => (int) ($record['sort_order'] ?? 0),
            'source_reference' => $record['source_reference'],
            'guidance' => [],
            'evidence_expectations' => [],
        ];
    }

    /**
     * @param array<string, string|null> $record
     * @return array<string, mixed>
     */
    private function csvObjectiveRow(array $record): array
    {
        return [
            'code' => $record['code'],
            'title_en' => $record['title_en'],
            'title_ar' => $record['title_ar'],
            'description_en' => $record['description_en'],
            'description_ar' => $record['description_ar'],
            'category_en' => $record['parent_ref'] ?: null,
            'sort_order' => (int) ($record['sort_order'] ?? 0),
            'source_reference' => $record['source_reference'],
        ];
    }
}
