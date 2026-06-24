# QCIF Sprint 6 — AI Consumption Contract Layer

**Phase:** AI-ready corpus contract (no AI execution)
**Scope:** Deterministic, structured JSON payloads that a *future* AI/RAG consumer can use as grounded context.
**Out of scope (explicitly NOT in this sprint):** OpenAI/LLM calls, RAG, vectors, embeddings, scoring, assessments, evidence upload, UI.

---

## Purpose

Sprints 1–5.1 built the NCA **ECC-2:2024** corpus (5 domains, 108 controls, 108 requirements, 1 active revision) and exposed read-only corpus APIs (global + workspace-scoped, QynShield-gated).

Sprint 6 adds a thin **AI Consumption Contract Layer** that repackages that corpus into **self-contained, deterministic, AI-ready payloads** with mandatory **citations** and a fixed **guardrails** block.

It is a *contract*, not an integration: it defines exactly what a future AI consumer will receive and the constraints it must honor. **No AI provider is called.**

---

## What this is / what this is NOT

| This IS | This is NOT |
| --- | --- |
| Deterministic JSON assembly from the corpus | An OpenAI / LLM / RAG / embeddings / vector call |
| Mandatory citations on every payload | Scoring, assessment, or maturity calculation |
| A fixed guardrails block per payload | Evidence ingestion or tenant-data processing |
| Bilingual (EN/AR) official corpus text | A UI or frontend feature |
| UUID-only identifiers | A new global AI endpoint (workspace-scoped only) |

Hard guarantees enforced in code:

- **No AI execution** — there are zero outbound AI/HTTP calls in this layer. Every payload carries `provenance.ai_executed = false`.
- **No tenant data / no evidence** — payloads carry `tenant_data_not_included = true` and `evidence_not_included = true`; `provenance.tenant_data_included = false`, `provenance.evidence_included = false`.
- **UUIDs only** — no numeric database identifiers ever appear in output.

---

## Architecture

```
Client
  │  GET /api/workspaces/{project}/compliance/ai-context/...
  ▼
auth:sanctum ──► project.qynshield (entitlement) ──► ProjectPolicy@view (membership)
  │
  ├─► ComplianceCorpusAccessAuditLogger::logAiContext()   (action: compliance_ai_context_access)
  │
  ├─► ComplianceCorpusCacheService::remember()            (key embeds active revision UUID)
  │
  └─► ComplianceAiContextService::build(contextType, frameworkKey, releaseCode, params)
         ├─ ComplianceCorpusQueryService     → resolve release + active revision + entities
         ├─ ComplianceAiPromptContextBuilder → self-contained "payload" (bilingual + provenance)
         ├─ ComplianceAiCitationBuilder      → citations[]
         └─ ComplianceAiGuardrailService     → guardrails block + validation (reject if invalid)
  ▼
{ "success": true, "data": { context_type, framework, release, revision, payload, citations, guardrails, generated_at } }
```

### Services

| Service | Responsibility |
| --- | --- |
| `ComplianceAiContextService` | Orchestrator + single entry point. Resolves the active revision, builds the payload, attaches citations, enforces guardrails, returns the envelope. |
| `ComplianceAiPromptContextBuilder` | Assembles the self-contained, AI-ready `payload` for each context type (bilingual text + per-entity provenance + source documents + entity index). |
| `ComplianceAiCitationBuilder` | Produces canonical citation records for domains/controls/requirements/source documents. |
| `ComplianceAiGuardrailService` | Owns the standard guardrails block, the list of supported context types, and all validation invariants. |

All services live in `app/Services/Compliance/Ai/`.

---

## Context types

| Context type | Endpoint | Required params |
| --- | --- | --- |
| `corpus_summary` | `/summary` | — |
| `domain_profile` | `/domains/{domainCode}` | domainCode |
| `control_profile` | `/controls/{controlCode}` | controlCode |
| `search_context` | `/search?q=` | q (≥ 2 chars) |
| `requirement_profile` | *(service-only — no endpoint this sprint)* | requirementCode |

Every payload includes: `framework`, `release`, `active_revision`, `source_documents`, entity UUIDs + codes (`entities`), bilingual EN/AR text, `provenance`, `generated_at`, and `guardrails`.

---

## Response format

```json
{
  "success": true,
  "data": {
    "context_type": "control_profile",
    "framework": {},
    "release": {},
    "revision": {},
    "payload": {},
    "citations": [],
    "guardrails": {},
    "generated_at": "2026-06-25T00:00:00+00:00"
  }
}
```

Errors:

| HTTP | Shape | When |
| --- | --- | --- |
| 404 | `{ "success": false, "message": "...", "code": "corpus_not_found" }` | unknown framework/release/domain/control/requirement, or **no active revision** |
| 422 | `{ "success": false, "message": "...", "code": "unsupported_context_type" \| "missing_citations" \| "missing_source_document" \| "missing_bilingual_text" \| "invalid_search_query" }` | failed validation / query too short |
| 403 | `{ "error": "module_not_entitled", "module": "qynshield" }` | missing QynShield entitlement (from middleware) |

---

## Payload examples

### `corpus_summary` (abridged)

```json
{
  "context_type": "corpus_summary",
  "framework": { "uuid": "…", "key": "nca-ecc", "code": "ECC", "title_en": "…", "title_ar": "…", "status": "published", "authority": { "uuid": "…", "key": "nca", "short_name": "NCA" } },
  "release": { "uuid": "…", "release_code": "ecc-2-2024", "version_code": "2:2024", "title_en": "…", "title_ar": "…", "stable_ref": "nca-ecc:2:2024" },
  "revision": { "uuid": "…", "revision_number": 1, "status": "active", "checksum_sha256": "…" },
  "payload": {
    "context_type": "corpus_summary",
    "framework": { "uuid": "…", "key": "nca-ecc", "code": "ECC", "title_en": "…", "title_ar": "…" },
    "release": { "uuid": "…", "release_code": "ecc-2-2024", "version_code": "2:2024", "title_en": "…", "title_ar": "…", "stable_ref": "nca-ecc:2:2024" },
    "active_revision": { "uuid": "…", "revision_number": 1, "status": "active", "checksum_sha256": "…" },
    "counts": { "domains": 5, "controls": 108, "requirements": 108, "guidance_items": 0, "evidence_expectations": 0 },
    "source_documents": [ { "uuid": "…", "key": "nca-ecc-2-2024", "title_en": "…", "title_ar": "…", "document_type": "framework", "language": "bilingual", "checksum_sha256": "…" } ],
    "entities": [],
    "provenance": { "framework_key": "nca-ecc", "release_code": "2:2024", "revision_uuid": "…", "revision_number": 1, "checksum_sha256": "…", "generated_at": "…", "tenant_data_included": false, "evidence_included": false, "ai_executed": false },
    "guardrails": { "...": true },
    "generated_at": "2026-06-25T00:00:00+00:00"
  },
  "citations": [
    { "source_document_key": "nca-ecc-2-2024", "source_title_en": "…", "source_title_ar": "…", "official_reference": null, "source_reference": "NCA", "source_page": null, "entity_uuid": "…", "entity_type": "source_document", "entity_code": "nca-ecc-2-2024" }
  ],
  "guardrails": { "...": true },
  "generated_at": "2026-06-25T00:00:00+00:00"
}
```

### `control_profile` payload (abridged)

```json
{
  "context_type": "control_profile",
  "domain": { "uuid": "…", "code": "1", "display_code": "1", "title_en": "…", "title_ar": "…", "description_en": "…", "description_ar": "…", "provenance": { "source_document_key": "nca-ecc-2-2024", "source_reference": "…", "source_page": "…", "official_reference": "…" } },
  "control": { "uuid": "…", "code": "1-1-1", "display_code": "1-1-1", "title_en": "…", "title_ar": "…", "description_en": "…", "description_ar": "…", "control_type": null, "provenance": { "...": "…" } },
  "requirements": [ { "uuid": "…", "code": "1-1-1-1", "title_en": "…", "title_ar": "…", "requirement_text_en": "…", "requirement_text_ar": "…", "provenance": { "...": "…" } } ],
  "source_documents": [ { "uuid": "…", "key": "nca-ecc-2-2024", "title_en": "…", "title_ar": "…" } ],
  "entities": [
    { "entity_type": "domain", "entity_uuid": "…", "entity_code": "1" },
    { "entity_type": "control", "entity_uuid": "…", "entity_code": "1-1-1" },
    { "entity_type": "requirement", "entity_uuid": "…", "entity_code": "1-1-1-1" }
  ],
  "provenance": { "framework_key": "nca-ecc", "release_code": "2:2024", "revision_uuid": "…", "ai_executed": false, "tenant_data_included": false, "evidence_included": false },
  "guardrails": { "...": true },
  "generated_at": "…"
}
```

`domain_profile` and `search_context` follow the same shape (`controls[]` for domains; `results.{domains,controls,requirements}` + `result_counts` for search).

---

## Guardrails

Every payload (and the envelope) embeds this immutable block. The contract layer does not allow callers to weaken it:

```json
{
  "use_only_provided_context": true,
  "do_not_invent_controls": true,
  "cite_every_claim": true,
  "preserve_official_wording": true,
  "bilingual_required": true,
  "no_legal_advice_disclaimer_required": true,
  "tenant_data_not_included": true,
  "evidence_not_included": true
}
```

A future AI consumer MUST honor all eight constraints.

---

## Citation rules

Every AI-ready payload carries a non-empty `citations[]`. **No citation ⇒ payload is invalid (rejected with HTTP 422).**

Citation shape (all keys always present):

| Field | Source |
| --- | --- |
| `source_document_key` | entity's `sourceDocument.key` |
| `source_title_en` | `sourceDocument.title_en` |
| `source_title_ar` | `sourceDocument.title_ar` |
| `official_reference` | entity `official_reference` (clause/article) |
| `source_reference` | entity `source_reference` |
| `source_page` | entity `source_page` |
| `entity_uuid` | entity `uuid` (never a numeric id) |
| `entity_type` | `domain` \| `control` \| `requirement` \| `source_document` |
| `entity_code` | entity `display_code` ?? `code` |

Rules enforced by `ComplianceAiGuardrailService::assertCitationsValid()`:

1. The citations array must be non-empty (`missing_citations`).
2. Every citation must resolve to a source document — a non-empty `source_document_key` (`missing_source_document`).

Behavior by context type:
- **Profiles** (`control`/`domain`/`requirement`): the *primary* entity must have a source document, else the payload is rejected. Related entities are cited only when they have a source document.
- **`corpus_summary`**: cites the release's official source document(s).
- **`search_context`**: cites every matched entity that has a source document; if a search matches nothing, it falls back to citing the release source document(s) so the "≥ 1 citation" invariant always holds.

---

## Validation (rejection rules)

`ComplianceAiContextService::build()` returns an error if:

| Condition | Mechanism | Code / HTTP |
| --- | --- | --- |
| Unsupported context type | `assertSupportedContextType()` | `unsupported_context_type` / 422 |
| No active revision | `ComplianceCorpusQueryService::getActiveRevision()` | `corpus_not_found` / 404 |
| Missing source document on a cited entity | `assertCitationsValid()` | `missing_source_document` / 422 |
| Missing citations entirely | `assertCitationsValid()` | `missing_citations` / 422 |
| Missing EN or AR text on the primary entity | `assertBilingualText()` | `missing_bilingual_text` / 422 |
| Search query too short (< 2 chars) | `ComplianceCorpusSearchService` | `invalid_search_query` / 422 |
| Unknown framework / release / domain / control / requirement | resolvers throw `ComplianceCorpusNotFoundException` | `corpus_not_found` / 404 |

---

## API endpoints (workspace-scoped only)

All under `auth:sanctum` (outer group) + `project.qynshield` + `ProjectPolicy@view` + audit + throttle + revision-keyed cache. `{project}` binds by numeric workspace id. Registered for both `projects/{project}` and `workspaces/{project}` prefixes.

```
GET /api/workspaces/{project}/compliance/ai-context/frameworks/{frameworkKey}/releases/{releaseCode}/summary
GET /api/workspaces/{project}/compliance/ai-context/frameworks/{frameworkKey}/releases/{releaseCode}/domains/{domainCode}
GET /api/workspaces/{project}/compliance/ai-context/frameworks/{frameworkKey}/releases/{releaseCode}/controls/{controlCode}
GET /api/workspaces/{project}/compliance/ai-context/frameworks/{frameworkKey}/releases/{releaseCode}/search?q=
```

> Global (non-workspace) AI-context endpoints are intentionally **not** registered in this sprint.

### curl examples

```bash
BASE="https://cloud.quenyx.com/api"
TOKEN="<sanctum token>"
PROJECT=69
FW=nca-ecc
REL=2:2024

# corpus summary
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "$BASE/workspaces/$PROJECT/compliance/ai-context/frameworks/$FW/releases/$REL/summary"

# domain context
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "$BASE/workspaces/$PROJECT/compliance/ai-context/frameworks/$FW/releases/$REL/domains/1"

# control context
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "$BASE/workspaces/$PROJECT/compliance/ai-context/frameworks/$FW/releases/$REL/controls/1-1-1"

# search context
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "$BASE/workspaces/$PROJECT/compliance/ai-context/frameworks/$FW/releases/$REL/search?q=governance"
```

---

## Caching & rate limits

- **Cache:** reuses `ComplianceCorpusCacheService` with `ai:*` segments. Cache keys embed the **active revision UUID**, so publishing a new revision automatically invalidates stale AI payloads.
- **Rate limits:** `compliance-ai-context-read` (default 120/min) and `compliance-ai-context-search` (default 30/min), keyed by user id (fallback IP). Configurable via `config/compliance.php` → `ai_context.rate_limits` (env: `COMPLIANCE_AI_CONTEXT_READ_RATE_LIMIT`, `COMPLIANCE_AI_CONTEXT_SEARCH_RATE_LIMIT`).

---

## Security model

1. **Authentication** — `auth:sanctum`.
2. **Entitlement** — `project.qynshield` (`EnsureQynShieldEntitlement`) — 403 `module_not_entitled` if the workspace lacks the QynShield module.
3. **Membership** — `ProjectPolicy@view` (owner or member).
4. **Audit** — every request writes `audit_logs` with `action = compliance_ai_context_access` and metadata `{ context_type, framework, release, endpoint }`.
5. **Data minimization** — corpus reference data only; **no tenant data, no evidence**. Enforced by guardrails + provenance flags.
6. **Determinism** — identical inputs (for a given active revision) produce identical payloads; no randomness, no external calls.
7. **Identifier safety** — UUIDs only; numeric primary keys are never serialized.

---

## Future OpenAI / RAG integration path (NOT implemented)

This layer is the stable seam a later sprint will build on, without changing the contract:

1. **Retrieval** — a future RAG indexer consumes these payloads (one per control/domain/requirement) as grounded chunks; `entities[]` + `citations[]` map directly to retrievable units and their provenance.
2. **Embeddings / vectors** — generated from `payload.*` bilingual text; `provenance.checksum_sha256` + `revision_uuid` give a cache/version key for re-embedding only when the revision changes.
3. **Prompting** — the `guardrails` block is injected as system constraints; `citations[]` become the "cite every claim" source set; `preserve_official_wording` keeps EN/AR text verbatim.
4. **Execution** — only at that future stage would an OpenAI/LLM call be added, behind its own entitlement/flag, reusing this contract's payloads unchanged.
5. **Tenant grounding** — tenant data/evidence (today excluded) would be added as a *separate*, clearly-flagged context section, never silently mixed into corpus citations.

Until then: **no AI execution, no vectors, no embeddings, no tenant data.**

---

## QA results

- `php -l` — clean on all new/changed PHP files.
- `php artisan route:list --path=compliance/ai-context` — 8 routes registered (4 endpoints × `projects`/`workspaces`), middleware confirmed: `auth:sanctum` → `EnsureQynShieldEntitlement` → `throttle:compliance-ai-context-(read|search)`.
- Contract invariants verified at runtime (DB-free): guardrails block (8/8 true), rejection of unsupported context type / empty citations / missing source document / missing AR text; citation completeness (9 fields, source-document key present); control_profile payload bilingual and **UUID-only (no numeric `id` keys)**; `provenance.ai_executed=false`, `tenant_data_included=false`, `evidence_included=false`.
- Permanent unit test: `tests/Unit/ComplianceAiContractTest.php`.
- Static check: no OpenAI/LLM/HTTP/embedding/vector calls exist in `app/Services/Compliance/Ai/` (only documentation references to a *future* consumer).

> Environment note: the local CLI PHP build lacks the `pdo_mysql` and `mbstring` extensions, so DB-backed functional execution and PHPUnit must be run on the server (or a CLI with those extensions). All non-DB logic was executed and verified locally.
