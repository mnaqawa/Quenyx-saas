# QCIF Sprint 3A — Domain-Based Corpus Population Workflow

**Phase:** Preparation only (Sprint 3A)  
**Scope:** Per-domain batch files, manifest loader, review lifecycle gates  
**Out of scope:** Schema changes, AI/OCR, automatic extraction, fake controls, full ECC import

---

## Objective

Prepare a **domain-by-domain** curation and import workflow for NCA ECC-2:2024 while keeping the Sprint 2A architecture frozen. Curators work one official ECC domain at a time; the importer merges approved batches into a single corpus payload.

---

## Folder layout

```
backend/database/corpus/nca/ecc-2-2024/
├── manifest.json                      # Framework + domain batch registry
├── source-documents.json              # Official EN/AR PDF metadata (seed separately)
├── curated-corpus.json                # Legacy single-file import (still supported)
├── README.md
├── governance/
│   └── domain.json                    # Governance domain batch
├── cybersecurity-defense/
│   └── domain.json
├── cybersecurity-resilience/
│   └── domain.json
├── third-party/
│   └── domain.json
├── cloud-security/
│   └── domain.json
└── ot-security/
    └── domain.json
```

Each `domain.json` is an independent review unit. Templates ship empty (`status: draft`, no `domain` entity yet).

---

## Review lifecycle

| Status | Meaning | Dry-run | Non-dry-run import |
|--------|---------|---------|-------------------|
| `draft` | Work in progress | Allowed when batch is empty | Allowed when batch is empty |
| `curated` | Human curation complete | Rejected if batch has entities | Rejected |
| `validated` | Passed validator review | Allowed | Rejected |
| `approved` | Ready for production import | Allowed | Allowed |
| `imported` | Previously imported | Allowed (warning) | Rejected |

**Rules:**

- Batches with **no corpus entities** (empty preparation templates) skip review gates — this supports manifest dry-runs before curation starts.
- Batches with **domain/control/requirement content** enforce the table above.
- `draft` batches with entities are always rejected.

### Review metadata (`domain.json`)

| Field | Type | Notes |
|-------|------|-------|
| `status` | enum | `draft` \| `curated` \| `validated` \| `approved` \| `imported` |
| `reviewed_by` | string\|null | Curator or approver identifier |
| `reviewed_at` | string\|null | ISO-8601 timestamp |
| `notes` | string | Free-text review notes |
| `metadata` | object | Structured curator metadata |
| `domain` | object | Domain entity (code, titles, controls[]) when populated |

Legacy inline domain shape (root-level `code`, `controls`) is also accepted inside batch files.

---

## Manifest contract

`manifest.json` registers framework context and domain batch files:

```json
{
  "$schema": "quenyx/qcif/corpus-manifest/v1.0",
  "framework": "nca-ecc",
  "release": "2:2024",
  "source_document_keys": [
    "nca-ecc-2-2024-en",
    "nca-ecc-2-2024-ar"
  ],
  "control_objectives": [],
  "objective_mappings": [],
  "domains": [
    { "slug": "governance", "file": "governance/domain.json" },
    { "slug": "cybersecurity-defense", "file": "cybersecurity-defense/domain.json" }
  ]
}
```

| Field | Required | Notes |
|-------|----------|-------|
| `framework` | Yes | String key (`nca-ecc`) or object (`key`, `version_code`) |
| `release` | Yes | Release version code (`2:2024`) |
| `domains[]` | Yes | `{ slug, file }` entries relative to manifest directory |
| `source_document_keys` | Yes | Same as single-file import |
| `control_objectives` | No | Shared across batches |
| `objective_mappings` | No | Shared across batches |

**Detection:** If `domains[0]` contains a `file` key (or `$schema` contains `corpus-manifest`), the loader treats the path as a manifest and merges batch files. Otherwise the file is loaded as a single corpus JSON (backward compatible).

---

## Validation gates (manifest + batches)

| Rule | Behavior |
|------|----------|
| Duplicate codes across batches | Rejected at merge time (domain codes) and in merged payload (controls/requirements) |
| Same domain slug twice | Rejected in manifest |
| Same domain code in two batch files | Rejected at merge time |
| Manifest domain file missing | Rejected |
| Empty domain batch | Accepted (skipped in merge until `domain` populated) |
| Empty domain entity (`controls: []`) | Accepted |

All Sprint 2/2A validator gates (provenance, placeholders, normalized codes, hierarchy) still apply to merged content.

---

## Revision flow

Unchanged from Sprint 2A:

1. Non-dry-run import completes successfully.
2. If `domains + controls + requirements > 0`, a `compliance_corpus_revisions` row is created.
3. Empty manifest dry-run or empty preparation import creates **no revision**.

Domain batches do not create per-domain revisions — only the merged import run produces a corpus revision.

After a successful domain batch import, curators should set batch `status` to `imported` manually for audit tracking (not enforced automatically in Sprint 3A).

---

## Batch import examples

### Dry-run empty manifest (preparation QA)

```bash
cd backend
php artisan compliance:seed-source-documents \
  database/corpus/nca/ecc-2-2024/source-documents.json \
  --framework=nca-ecc --release=2:2024

php artisan compliance:import-corpus \
  database/corpus/nca/ecc-2-2024/manifest.json \
  --dry-run --framework=nca-ecc --release=2:2024
```

Expected: status `completed`, zero entity writes, zero revisions.

### Dry-run one validated domain batch (future)

1. Populate `governance/domain.json` with official NCA content.
2. Set `status` to `validated`.
3. Run manifest dry-run — governance domain merges and validates.

### Production import one approved domain (future)

1. Set `governance/domain.json` `status` to `approved`.
2. Run non-dry-run manifest import.
3. Corpus revision v1 created when first real entities land.

### Legacy single-file import (unchanged)

```bash
php artisan compliance:import-corpus \
  database/corpus/nca/ecc-2-2024/curated-corpus.json \
  --dry-run --framework=nca-ecc --release=2:2024
```

---

## QA commands

```bash
cd backend
php artisan migrate --force
php artisan db:seed --class=ComplianceCorpusSeeder --force

php artisan compliance:seed-source-documents \
  database/corpus/nca/ecc-2-2024/source-documents.json \
  --framework=nca-ecc --release=2:2024

php artisan compliance:import-corpus \
  database/corpus/nca/ecc-2-2024/manifest.json \
  --dry-run --framework=nca-ecc --release=2:2024

php artisan tinker --execute="
echo 'domains: '.DB::table('compliance_domains')->count().PHP_EOL;
echo 'controls: '.DB::table('compliance_controls')->count().PHP_EOL;
echo 'requirements: '.DB::table('compliance_requirements')->count().PHP_EOL;
echo 'revisions: '.\App\Models\Compliance\ComplianceCorpusRevision::count().PHP_EOL;
"
# Expected: domains=0 controls=0 requirements=0 revisions=0
```

**Syntax check:**

```bash
php -l app/Enums/Compliance/DomainBatchStatus.php
php -l app/Services/Compliance/Corpus/ComplianceCorpusManifestLoader.php
php -l app/Services/Compliance/Corpus/ComplianceCorpusPayloadLoader.php
php -l app/Services/Compliance/Corpus/ComplianceCorpusValidator.php
```

---

## Related docs

- [QCIF Sprint 2A Versioning](./QCIF_SPRINT2A_VERSIONING.md)
- [QCIF Sprint 2 NCA ECC Corpus](./QCIF_SPRINT2_NCA_ECC_CORPUS.md)

---

**Verdict:** QCIF Sprint 3A complete. Domain-by-domain workflow ready. Architecture unchanged. Ready for manual NCA ECC domain population (Sprint 3B+).
