# QCIF Sprint 5 — Compliance Intelligence Layer v0

**Phase:** Read-only corpus consumption API  
**Scope:** Trusted NCA ECC-2:2024 corpus exposure for QynShield / future AI  
**Out of scope:** AI, RAG, vectors, scoring, tenant assessments, evidence upload, dashboards, UI

---

## Purpose

Sprint 5 exposes **Corpus Revision v1** (and future revisions) through a **read-only HTTP API** so authenticated clients can navigate, inspect, and search official compliance content without mutating corpus data.

The layer sits above the immutable QCIF corpus tables populated by Sprint 4 import. It is the foundation for future QynShield intelligence and AI-assisted workflows — but Sprint 5 deliberately includes **no AI or vector search**.

---

## Architecture

```
Client (Sanctum token)
    │
    ▼
/api/compliance/corpus/*  (auth:sanctum)
    │
    ▼
ComplianceCorpusController
    │
    ├── ComplianceCorpusQueryService      (release/revision resolve, summary, control profile)
    ├── ComplianceCorpusNavigationService (frameworks, domains, controls lists)
    └── ComplianceCorpusSearchService     (SQL LIKE search, grouped results)
    │
    ▼
Eloquent models (compliance_* tables)
    │
    ▼
Active ComplianceCorpusRevision (status=active)
```

### Services

| Service | Responsibility |
|---------|----------------|
| `ComplianceCorpusQueryService` | Resolve framework release, active revision, summary counts, domain/control lookup, full control profile |
| `ComplianceCorpusNavigationService` | List frameworks, releases, domains, controls, requirements |
| `ComplianceCorpusSearchService` | Text search across domains, controls, requirements (min 2 chars, limit 25 default / 100 max) |
| `ComplianceCorpusBatchMetadataReader` | Reads `pending_manual_review` from on-disk domain batch JSON (curator metadata not stored in DB) |

### API resources

All responses use Laravel API Resources under `App\Http\Resources\Compliance\`:

- `ComplianceFrameworkResource`
- `ComplianceFrameworkReleaseResource`
- `ComplianceCorpusRevisionResource`
- `ComplianceDomainResource`
- `ComplianceControlResource`
- `ComplianceRequirementResource`
- `ComplianceSourceDocumentResource`

**Rules:** UUIDs only (no numeric IDs), bilingual EN/AR fields, source provenance on every entity.

---

## Endpoints

Base path: **`/api/compliance/corpus`**

All routes require **`Authorization: Bearer {sanctum_token}`**.

| Method | Path | Description |
|--------|------|-------------|
| GET | `/frameworks` | List published framework families |
| GET | `/frameworks/{frameworkKey}/releases` | List releases for a framework |
| GET | `/frameworks/{frameworkKey}/releases/{releaseCode}/summary` | Release summary + active revision + counts |
| GET | `/frameworks/{frameworkKey}/releases/{releaseCode}/domains` | List domains |
| GET | `/frameworks/{frameworkKey}/releases/{releaseCode}/domains/{domainCode}` | Domain detail + controls |
| GET | `/frameworks/{frameworkKey}/releases/{releaseCode}/controls/{controlCode}` | Full control profile |
| GET | `/frameworks/{frameworkKey}/releases/{releaseCode}/search?q=` | Grouped search |

### NCA ECC-2:2024 examples

Use URL-encoded release code: `2:2024` → `2%3A2024`

```
GET /api/compliance/corpus/frameworks/nca-ecc/releases/2%3A2024/summary
GET /api/compliance/corpus/frameworks/nca-ecc/releases/2%3A2024/domains
GET /api/compliance/corpus/frameworks/nca-ecc/releases/2%3A2024/domains/1
GET /api/compliance/corpus/frameworks/nca-ecc/releases/2%3A2024/controls/1-1-1
GET /api/compliance/corpus/frameworks/nca-ecc/releases/2%3A2024/search?q=governance&limit=25
```

---

## Response examples

### Summary

```json
{
  "success": true,
  "data": {
    "framework": {
      "uuid": "...",
      "key": "nca-ecc",
      "title_en": "NCA Essential Cybersecurity Controls",
      "title_ar": "الضوابط الأساسية للأمن السيبراني"
    },
    "release": {
      "uuid": "...",
      "release_code": "ECC-2:2024",
      "version_code": "2:2024",
      "stable_ref": "nca-ecc:2:2024"
    },
    "active_revision": {
      "uuid": "...",
      "revision_number": 1,
      "status": "active",
      "checksum_sha256": "...",
      "entity_counts": {
        "domains": 5,
        "controls": 108,
        "requirements": 108,
        "guidance_items": 0
      },
      "import_run_uuid": "a3e4ce85-82e8-4f66-af26-6a5598c2f0d6"
    },
    "counts": {
      "domains": 5,
      "controls": 108,
      "requirements": 108,
      "guidance_items": 0,
      "evidence_expectations": 0
    },
    "source_documents": [
      {
        "uuid": "...",
        "key": "nca-ecc-2-2024-en",
        "title_en": "...",
        "source_url": "https://cdn.nca.gov.sa/..."
      }
    ],
    "pending_manual_review": []
  }
}
```

### Control profile

Returns framework, release, revision, domain, control, and requirements (with guidance/evidence when present).

### Search

```json
{
  "success": true,
  "data": {
    "query": "governance",
    "limit": 25,
    "results": {
      "domains": [],
      "controls": [],
      "requirements": []
    }
  }
}
```

Search rules:
- Minimum query length: **2**
- Default limit: **25**, maximum: **100**
- Matches: `code`, `display_code`, `normalized_code`, titles, requirement text (SQL `LIKE`)
- **No** full-text index, **no** vectors, **no** external services

---

## Security / RBAC

| Layer | Applied |
|-------|---------|
| Sanctum `auth:sanctum` | **Yes** — all corpus routes |
| Project/workspace scope | **No** — corpus is global reference data |
| `project.module:qynshield` | **Not applied** — existing middleware requires `{project}` route parameter |

### Entitlement gap (documented)

Workspace-scoped module middleware (`project.module:qynshield`) cannot gate global corpus routes without adding a project context. Sprint 5 uses **authenticated access only**.

**Recommended follow-up:** add optional `?project={uuid}` or workspace-prefixed alias routes with `project.module:qynshield` when QynShield UI integrates.

---

## What this is NOT

- Not an AI or chat endpoint
- Not RAG or vector search
- Not compliance scoring or maturity assessment
- Not tenant evidence collection
- Not a write/import API (use `compliance:import-corpus` for corpus changes)
- Not a dashboard or UI

---

## QA commands

### List routes

```bash
cd backend
php artisan route:list | findstr compliance
```

### Syntax check

```bash
php -l app/Services/Compliance/ComplianceCorpusQueryService.php
php -l app/Services/Compliance/ComplianceCorpusNavigationService.php
php -l app/Services/Compliance/ComplianceCorpusSearchService.php
php -l app/Http/Controllers/Compliance/ComplianceCorpusController.php
```

### DB verification (production)

```bash
php artisan tinker --execute="
echo 'domains: '.DB::table('compliance_domains')->count().PHP_EOL;
echo 'controls: '.DB::table('compliance_controls')->count().PHP_EOL;
echo 'requirements: '.DB::table('compliance_requirements')->count().PHP_EOL;
echo 'revisions: '.\App\Models\Compliance\ComplianceCorpusRevision::count().PHP_EOL;
"
```

Expected: `domains=5`, `controls=108`, `requirements=108`, `revisions=1`

### curl examples

```bash
TOKEN="your-sanctum-token"
BASE="https://your-cloudquenyx-host/api/compliance/corpus"

curl -s -H "Authorization: Bearer $TOKEN" "$BASE/frameworks/nca-ecc/releases/2%3A2024/summary" | jq .

curl -s -H "Authorization: Bearer $TOKEN" "$BASE/frameworks/nca-ecc/releases/2%3A2024/domains" | jq .

curl -s -H "Authorization: Bearer $TOKEN" "$BASE/frameworks/nca-ecc/releases/2%3A2024/controls/1-1-1" | jq .

curl -s -H "Authorization: Bearer $TOKEN" "$BASE/frameworks/nca-ecc/releases/2%3A2024/search?q=governance" | jq .
```

---

## Next sprint path (toward AI)

1. **Sprint 5.1** — Workspace-scoped routes + `qynshield` module entitlement
2. **Sprint 6** — Control graph / cross-reference helpers (still read-only)
3. **Future** — AI consumption layer: structured prompts from control profiles, citation to revision UUID + control UUID (no corpus mutation)
4. **Future** — Optional full-text index if LIKE search becomes insufficient (still no vectors unless explicitly scoped)

Corpus revisions remain immutable. AI and assessments must reference `active_revision.uuid` and entity UUIDs — never modify Revision v1 in place.

---

## Related docs

- [QCIF Sprint 2A Versioning](./QCIF_SPRINT2A_VERSIONING.md)
- [QCIF Sprint 3 Domain Workflow](./QCIF_SPRINT3_DOMAIN_WORKFLOW.md)
- [QCIF Sprint 1 Corpus](./QCIF_SPRINT1_CORPUS.md)

---

**Verdict:** QCIF Sprint 5 complete. Read-only Compliance Intelligence Layer available over NCA ECC-2:2024 Corpus Revision v1.
