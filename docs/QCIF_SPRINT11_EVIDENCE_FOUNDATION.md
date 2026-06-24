# QCIF Sprint 11 — Evidence Intelligence Foundation

**Phase:** Evidence as a first-class object (no AI, no uploads)
**Scope:** Evidence data model, four evidence services, lifecycle states, relationships, the first Evidence AI skill, and a read-only workspace API.
**Explicitly NOT in this sprint:** file uploads, blob storage, OCR, document parsing, assessments, Gap Assessment, Recommendations, Copilot, or any AI execution. This sprint *defines* evidence — it does not collect, parse, or reason over file contents.

---

## Why this exists

Through Sprint 10, QCIF could describe *what evidence a framework expects* (the corpus-side
`compliance_evidence_expectations` and `compliance_evidence_types`). It had no concept of the
*actual evidence a tenant holds*. Sprint 11 introduces **tenant evidence as a first-class
object**: a structured, workspace-scoped record that can satisfy one or more requirements,
moves through a defined lifecycle, and exposes a clean Evidence Context for future AI skills —
all without touching a single file byte.

This is the foundation that later sprints (collection, OCR, validation workflows, Gap
Assessment, Copilot) build on.

---

## Data model

| Object | Type | Purpose |
| --- | --- | --- |
| `ComplianceEvidence` | model / `compliance_evidence` | The tenant evidence record (workspace-scoped) |
| `ComplianceEvidenceType` | **existing corpus model** (reused) | Evidence type catalog |
| `ComplianceEvidenceRelationship` | model / `compliance_evidence_relationships` | Links evidence → requirement/control/domain/framework (many) |
| `ComplianceEvidenceLifecycle` | model / `compliance_evidence_lifecycle_events` | Append-only transition history |
| `ComplianceEvidenceStatus` | enum | The seven lifecycle states |

`ComplianceEvidence` supports: **UUID**, **workspace** (`project_id`), **framework**,
**release**, **revision**, **control**, **requirement**, **source**, **timestamps**
(`collected_at`/`validated_at`/`approved_at`/`valid_from`/`expires_at`), and **metadata**. All
external exposure is **UUID-only** — numeric ids never leave the services.

> Note: `ComplianceEvidenceType` already existed from the Corpus Engine, so it is **reused**
> rather than recreated; tenant evidence references it via `evidence_type_id`.

---

## Lifecycle

States (`ComplianceEvidenceStatus`): `registered`, `collected`, `validated`, `approved`,
`expired`, `rejected`, `archived`.

Allowed transitions (owned by `EvidenceLifecycleService`):

```
registered → collected | rejected | archived
collected  → validated | rejected | archived
validated  → approved  | rejected | expired | archived
approved   → expired   | rejected | archived
expired    → collected | archived
rejected   → collected | archived
archived   → (terminal)
```

Every transition is written to the append-only `compliance_evidence_lifecycle_events` log with
the actor and an optional reason. The Sprint 11 API is **read-only** and only reads the status
catalog; `transition()` exists for future workflow sprints.

---

## Relationships

```
Evidence → Requirement → Control → Domain → Framework
```

`EvidenceRelationshipService` resolves this chain from corpus data for every linked entity. A
single evidence can satisfy **multiple requirements** — each link is represented as its own row
in `compliance_evidence_relationships` (plus the optional primary `control_id`/`requirement_id`
anchor on the evidence itself). Chains are UUID-only and derived purely from corpus
relationships (no fabrication).

---

## Services

| Service | Responsibility |
| --- | --- |
| `EvidenceNormalizationService` | The single DB read boundary: workspace evidence retrieval (with filters), UUID-only evidence nodes, evidence type catalog |
| `EvidenceRelationshipService` | Builds the Evidence → Requirement → Control → Domain → Framework chains (supports many requirements per evidence) |
| `EvidenceLifecycleService` | Allowed transitions, status catalog, append-only `transition()` (future use) |
| `EvidenceValidationService` | Deterministic metadata/temporal validation (completeness, expiry, validity window) — no file inspection |

### Normalization
Turns stored rows into canonical, UUID-only nodes and supplies the type catalog. No file/blob
access; it normalizes *records*, not documents.

### Validation
Reasons only over the structured record + its relationships: missing title (issue), missing
type/source (warnings), no requirement/control link (issue), past expiry (warning), inverted
validity window (issue). **No OCR or content parsing.**

---

## First AI skill

`EvidenceSkill` (`evidence`, context type `evidence_context`) composes the four services into an
**Evidence Context**: normalized evidence nodes, their relationship chains, validation results,
and the status catalog. It reuses evidence services only — **no prompts, no OpenAI, no AI, and
no DB access of its own.** Registered in `config('ai.skills.registered')` (priority 70,
`AI_SKILL_EVIDENCE_ENABLED`), so it also participates in the Sprint 10 Skills Framework.

---

## API (workspace-only, read-only)

| Method | Endpoint | Returns |
| --- | --- | --- |
| `POST` | `/api/workspaces/{project}/compliance/evidence/context` | Evidence Context (nodes + relationships + validation + statuses) |
| `GET` | `/api/workspaces/{project}/compliance/evidence/types` | Evidence type catalog |
| `GET` | `/api/workspaces/{project}/compliance/evidence/statuses` | Lifecycle status catalog |

Security: sanctum auth + `project.qynshield` entitlement + `ProjectPolicy::view` membership +
audit logging (`compliance_evidence_access`, never content) + `throttle:compliance-evidence-read`
(default 120/min). Also registered under the `projects/{project}` prefix.

---

## Future: OCR, uploads, AI

This foundation is deliberately content-free so the following can be layered without schema
churn:

- **Future uploads** — a file/object will attach to a `ComplianceEvidence` record (e.g. a
  `compliance_evidence_files` table + storage driver); the evidence record already exists.
- **Future OCR / document parsing** — extracted text/metadata will enrich `metadata` and feed
  validation; the lifecycle (`collected → validated`) already models the workflow.
- **Future AI** — Gap Assessment and Copilot will consume `EvidenceSkill`'s Evidence Context
  alongside corpus/graph/mapping skills via the orchestrator. No AI runs in this sprint.

---

## QA results

- **Evidence model** — `compliance_evidence` with workspace/framework/release/revision/control/
  requirement/source/timestamps/metadata; UUID auto-assigned via `HasComplianceUuid`.
- **Relationships** — many-to-many evidence↔entity; chain resolves Requirement→Control→Domain→
  Framework; one evidence can satisfy multiple requirements.
- **Lifecycle** — 7 states + guarded transitions + append-only history.
- **AI skill** — `EvidenceSkill` returns Evidence Context by reusing services; no AI/HTTP.
- **UUID only** — node builders expose `uuid`/codes, never numeric ids.
- **No AI execution** — static scan of evidence services + skill for provider/OpenAI/HTTP →
  none.
- `php -l` clean; `route:list` shows the three evidence routes (projects + workspaces).

---

## Files changed

**New**
- `backend/app/Enums/Compliance/Evidence/ComplianceEvidenceStatus.php`
- `backend/app/Models/Compliance/Evidence/` — `ComplianceEvidence`, `ComplianceEvidenceRelationship`, `ComplianceEvidenceLifecycle`
- `backend/app/Services/Compliance/Evidence/` — `EvidenceNormalizationService`, `EvidenceRelationshipService`, `EvidenceLifecycleService`, `EvidenceValidationService`
- `backend/app/Services/Ai/Skills/EvidenceSkill.php`
- `backend/app/Http/Controllers/Compliance/ComplianceEvidenceController.php`
- `backend/routes/compliance-evidence.php`
- `backend/database/migrations/2026_06_25_020000_create_compliance_evidence_tables.php`
- `backend/tests/Unit/EvidenceFoundationTest.php`
- `docs/QCIF_SPRINT11_EVIDENCE_FOUNDATION.md`

**Modified**
- `backend/config/ai.php` (register `evidence` skill)
- `backend/config/compliance.php` (evidence rate limit)
- `backend/app/Services/Compliance/ComplianceCorpusAccessAuditLogger.php` (`logEvidence`)
- `backend/routes/api.php` (wire evidence routes)
- `backend/app/Providers/RouteServiceProvider.php` (`compliance-evidence-read` limiter)
