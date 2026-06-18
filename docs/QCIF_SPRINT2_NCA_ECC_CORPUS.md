# QCIF Sprint 2 — NCA ECC-2:2024 Corpus Population Readiness

**Phase:** Preparation only (Sprint 2.0)  
**Scope:** Official-source curation workflow, provenance fields, validator gates  
**Out of scope:** Control population, AI/RAG, scoring, UI, file upload

---

## Objective

Prepare a production-grade workflow for manually curated import of NCA ECC-2:2024 from **official NCA sources only**:

- [NCA ECC portal page](https://nca.gov.sa/en/regulatory-documents/controls-list/ecc/)
- [Official English PDF](https://cdn.nca.gov.sa/api/files/public/upload/86e09090-44e4-481f-bc28-355673607654_ECC--2024-EN.pdf)
- [Official Arabic PDF](https://cdn.nca.gov.sa/api/files/public/upload/29a9e86a-595f-4af9-8db5-88715a458a14_ECC-2-2024---NCA.pdf)

No controls are imported in this sprint slice — only pipeline and workspace preparation.

---

## Curation workspace

```
backend/database/corpus/nca/ecc-2-2024/
├── README.md                 # Local workflow + QA commands
├── source-documents.json     # Official EN/AR PDF metadata
└── curated-corpus.json       # Working import file (empty domains[])
```

---

## Source document registration

Metadata-only — no upload, no local file storage.

```bash
php artisan compliance:seed-source-documents \
  database/corpus/nca/ecc-2-2024/source-documents.json \
  --framework=nca-ecc --release=2:2024
```

Registers:

| Key | Language | Official PDF URL |
|-----|----------|------------------|
| `nca-ecc-2-2024-en` | en | NCA CDN English PDF |
| `nca-ecc-2-2024-ar` | ar | NCA CDN Arabic PDF |

Fields use `official_file_name`, `official_file_mime`, `official_file_size` for **external document metadata only**.

---

## Corpus JSON contract (v2.0-preparation)

### Root fields

| Field | Required | Notes |
|-------|----------|-------|
| `framework.key` | Yes | `nca-ecc` |
| `framework.version_code` | Yes | `2:2024` |
| `source_document_keys` | Yes | Must match seeded keys |
| `domains` | No (empty OK) | Populated during manual curation |
| `control_objectives` | No | Optional |
| `objective_mappings` | No | Optional |

### Entity provenance (when curating)

| Field | Domains | Controls | Requirements | Guidance | Evidence |
|-------|---------|----------|--------------|----------|----------|
| `source_document_key` | Optional | **Required** | **Required** | Optional | Optional |
| `source_page` | Optional | **Required*** | Optional | Optional | Optional |
| `source_reference` | Optional | **Required*** | **Required** | **Required** | Optional |
| `official_reference` | Optional | Optional | Optional | Optional | Optional |
| `metadata` | Optional | Optional | Optional | Optional | Optional |

\* At least one of `source_page`, `source_reference`, or `official_reference` required for controls.

---

## Validator gates

`ComplianceCorpusValidator` rejects:

1. Missing or unregistered `source_document_keys`
2. Missing EN/AR title fields on domains/controls/requirements
3. Duplicate control codes (global) and requirement codes (per control)
4. Controls without official provenance when corpus entities present
5. Requirements without `source_reference` or `official_reference`
6. Placeholder text: `TBD`, `sample`, `lorem`, `test control`, `fake`, `example`, `demo`, `xxx`, `todo`
7. Code markers: `EXAMPLE`, `SAMPLE`, `TEST-`, `FAKE`, `LOREM`, `DEMO`

Strict provenance applies only when `domains[].controls` is non-empty.

---

## Manual curation workflow

1. **Register sources** — run `compliance:seed-source-documents`
2. **Open official PDFs** — EN and AR side by side
3. **One domain at a time** — do not bulk-paste the full framework
4. **Pair bilingual fields** — `title_en`/`title_ar`, `requirement_text_en`/`requirement_text_ar`
5. **Add provenance** — `source_document_key`, `source_page`, `source_reference`
6. **Validate JSON** — syntax check + dry-run import
7. **Dry-run** — `compliance:import-corpus ... --dry-run`
8. **Review** — import run summary, logs, DB counts
9. **Approve** — human sign-off required
10. **Import** — run without `--dry-run`
11. **Rollback if needed** — `--rollback=<uuid>` creates audit rollback run

---

## Schema changes (Sprint 2 preparation)

**Migration:** `2026_06_18_130000_qcif_sprint_2_corpus_provenance_preparation.php`

| Change | Tables |
|--------|--------|
| `key` on source documents | `compliance_source_documents` |
| Provenance columns | `compliance_domains`, `compliance_controls`, `compliance_requirements`, `compliance_guidance_items`, `compliance_evidence_expectations` |

Provenance columns:

- `source_document_id` (nullable FK)
- `source_page` (nullable)
- `source_reference` (existing, kept)
- `official_reference` (nullable)
- `metadata` (nullable JSON)

---

## QA command set

```bash
cd backend

# 1. Migrate + base seed
php artisan migrate --force
php artisan db:seed --class=ComplianceCorpusSeeder --force

# 2. Register official source documents
php artisan compliance:seed-source-documents \
  database/corpus/nca/ecc-2-2024/source-documents.json \
  --framework=nca-ecc --release=2:2024

# 3. Dry-run empty curated corpus (expect success, no entity writes)
php artisan compliance:import-corpus \
  database/corpus/nca/ecc-2-2024/curated-corpus.json \
  --dry-run --framework=nca-ecc --release=2:2024

# 4. Production import (after curation + approval only)
php artisan compliance:import-corpus \
  database/corpus/nca/ecc-2-2024/curated-corpus.json \
  --framework=nca-ecc --release=2:2024

# 5. Rollback example
php artisan compliance:import-corpus \
  database/corpus/nca/ecc-2-2024/curated-corpus.json \
  --rollback=<completed-import-run-uuid>

# 6. DB count checks
php artisan tinker --execute="
echo 'source_docs: '.DB::table('compliance_source_documents')->count().PHP_EOL;
echo 'domains: '.DB::table('compliance_domains')->count().PHP_EOL;
echo 'controls: '.DB::table('compliance_controls')->count().PHP_EOL;
echo 'requirements: '.DB::table('compliance_requirements')->count().PHP_EOL;
"

# 7. Verify source document keys
php artisan tinker --execute="
\App\Models\Compliance\ComplianceSourceDocument::query()
  ->whereNotNull('key')->pluck('key')->each(fn(\$k) => print(\$k.PHP_EOL));
"
```

---

## Rollback audit trail

Rollback creates a **new** import run (`import_type=rollback`) linked via `rollback_of_import_run_id`. Original run marked `rolled_back`.

---

## Remaining risks

| Risk | Mitigation |
|------|------------|
| NCA PDF URL changes | Update `source-documents.json`; re-run seed command |
| Manual transcription errors | Bilingual pairing + official page references required |
| Premature import | Dry-run default in workflow; draft status on entities |
| Large corpus JSON errors | Curate one domain at a time; incremental dry-runs |
| Validator false positives on legitimate text containing "example" | Curators cite official wording; adjust patterns if needed |

---

## Related documentation

- [QCIF Sprint 1 Corpus Foundation](./QCIF_SPRINT1_CORPUS.md)
- [QCIF Sprint 2A Versioning & Hierarchy](./QCIF_SPRINT2A_VERSIONING.md)
- Workspace README: `backend/database/corpus/nca/ecc-2-2024/README.md`

---

**Verdict:** Sprint 2 preparation complete. Curation workspace ready. Do not populate controls until official PDF review is complete.
