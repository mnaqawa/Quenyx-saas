# NCA ECC-2:2024 — Curated Corpus Workspace

**Status:** Preparation only — no controls populated yet.

This directory is the human curation workspace for the official NCA Essential Cybersecurity Controls (ECC) release **2:2024**.

## Official sources (external only)

| Key | Language | Official PDF |
|-----|----------|--------------|
| `nca-ecc-2-2024-en` | English | [NCA CDN EN PDF](https://cdn.nca.gov.sa/api/files/public/upload/86e09090-44e4-481f-bc28-355673607654_ECC--2024-EN.pdf) |
| `nca-ecc-2-2024-ar` | Arabic | [NCA CDN AR PDF](https://cdn.nca.gov.sa/api/files/public/upload/29a9e86a-595f-4af9-8db5-88715a458a14_ECC-2-2024---NCA.pdf) |

Portal page: [NCA ECC regulatory documents](https://nca.gov.sa/en/regulatory-documents/controls-list/ecc/)

Quenyx stores **metadata only**. PDFs are not uploaded or hosted by the platform.

## Files

| File | Purpose |
|------|---------|
| `manifest.json` | Domain batch registry — import via manifest (Sprint 3A) |
| `source-documents.json` | Official EN/AR PDF metadata — seed via Artisan |
| `curated-corpus.json` | Legacy single-file import — still supported |
| `{domain-slug}/domain.json` | Per-domain curation batch with review metadata |

See `docs/QCIF_SPRINT3_DOMAIN_WORKFLOW.md` for the domain-by-domain workflow.

## Setup (once per environment)

```bash
cd backend
php artisan migrate --force
php artisan db:seed --class=ComplianceCorpusSeeder --force

php artisan compliance:seed-source-documents \
  database/corpus/nca/ecc-2-2024/source-documents.json \
  --framework=nca-ecc --release=2:2024
```

## Manual curation checklist

1. Open the **official EN PDF** and **official AR PDF** side by side.
2. Work **one domain at a time** (do not bulk-paste the full framework).
3. For each domain/control/requirement:
   - Pair **EN and AR** title and normative text exactly from the official PDFs.
   - Set `source_document_key` to `nca-ecc-2-2024-en` or `nca-ecc-2-2024-ar` as appropriate.
   - Set `source_page` (e.g. `"42"` or `"C-12"`).
   - Set `source_reference` and/or `official_reference` citing the official section.
4. Save incrementally to the relevant `{domain-slug}/domain.json` batch file (or `curated-corpus.json` for legacy single-file import).
5. Set batch `status` to `validated` before dry-run, `approved` before production import.
6. Run **dry-run** import against `manifest.json` and fix all validator errors.
6. Review DB diff / import run summary.
7. Import only after human approval — never import placeholder or sample text.

## Validation gates (automatic)

The importer rejects:

- Missing `source_document_keys` at corpus root
- Unregistered `source_document_key` on entities
- Missing EN/AR title fields
- Duplicate control or requirement codes
- Controls without official source page/reference (when corpus entities present)
- Requirements without official source reference
- Placeholder text: TBD, sample, lorem, test control, fake, example, etc.
- Code markers: EXAMPLE, SAMPLE, TEST-, FAKE, LOREM

## QA commands

```bash
# Dry-run empty manifest (preparation — no DB writes)
php artisan compliance:import-corpus database/corpus/nca/ecc-2-2024/manifest.json \
  --dry-run --framework=nca-ecc --release=2:2024

# Dry-run legacy single file (no DB writes)
php artisan compliance:import-corpus database/corpus/nca/ecc-2-2024/curated-corpus.json \
  --dry-run --framework=nca-ecc --release=2:2024

# Production import (after batch approval only)
php artisan compliance:import-corpus database/corpus/nca/ecc-2-2024/manifest.json \
  --framework=nca-ecc --release=2:2024

# Rollback a completed import
php artisan compliance:import-corpus curated-corpus.json --rollback=<import-run-uuid>

# DB counts
php artisan tinker --execute="
echo 'domains: '.DB::table('compliance_domains')->count().PHP_EOL;
echo 'controls: '.DB::table('compliance_controls')->count().PHP_EOL;
echo 'requirements: '.DB::table('compliance_requirements')->count().PHP_EOL;
echo 'source_docs: '.DB::table('compliance_source_documents')->count().PHP_EOL;
"
```

## Entity provenance fields (JSON)

Each domain, control, requirement, guidance item, and evidence expectation supports:

| Field | Required when curating | Description |
|-------|------------------------|-------------|
| `source_document_key` | Controls & requirements | Registered official PDF key |
| `source_page` | Controls | Page in official PDF |
| `source_reference` | Requirements | Human-readable citation |
| `official_reference` | Optional | Structured official pointer |
| `metadata` | Optional | Curator notes (JSON) |

See `docs/QCIF_SPRINT2_NCA_ECC_CORPUS.md` for full workflow documentation.
