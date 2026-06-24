# QCIF Sprint 7 — Compliance Knowledge Graph Layer

**Phase:** Intra-framework knowledge graph (no AI execution)
**Scope:** Deterministic, UUID-only graph navigation of the corpus tree — Domain → Control → Requirement (plus control self-hierarchy).
**Out of scope (explicitly NOT in this sprint):** UI, vectors, RAG, OpenAI/LLM calls, assessments, scoring, evidence collection, cross-framework mappings (interface seam only).

---

## Purpose

Sprints 1–6 built the NCA **ECC-2:2024** corpus (5 domains, 108 controls, 108 requirements, 1 active revision `v1`), revisioning, provenance, citations, read-only corpus APIs, workspace access, and the AI Consumption Contract Layer.

Sprint 7 adds a **Knowledge Graph Layer**: a deterministic way to traverse the structural relationships *inside* a single framework release and retrieve, for any node, its **ancestors**, **descendants**, and **siblings** as compact, UUID-only graph nodes.

It is the structural backbone a future AI/RAG consumer needs to expand context ("give me this control plus its domain and sibling controls") without guessing relationships. **No AI provider is called.**

---

## What this is / what this is NOT

| This IS | This is NOT |
| --- | --- |
| Deterministic graph traversal of the corpus tree | An OpenAI / LLM / RAG / embeddings / vector call |
| UUID-only nodes with codes, EN/AR titles, provenance | Scoring, assessment, or maturity calculation |
| Release-scoped, active-revision-aware | Evidence ingestion or tenant-data processing |
| Workspace-scoped, QynShield-gated, audited, cached | A UI or frontend feature |
| A future cross-framework seam (interface only) | Any cross-framework *mapping* or implementation |

Hard guarantees enforced in code:

- **No AI execution** — zero outbound AI/HTTP calls in this layer.
- **UUIDs only** — no numeric database identifiers ever appear in graph output.
- **Intra-framework only** — `cross_references.cross_framework` is always `[]` this sprint (no provider bound).

---

## Architecture

```
Client
  → /api/workspaces/{project}/compliance/graph/...   (auth:sanctum)
      → project.qynshield middleware (membership + entitlement)
      → throttle:compliance-graph-read
      → ComplianceGraphController
          → authorize('view', project)  +  audit logGraph()
          → ComplianceCorpusCacheService::remember(revision-keyed)
              → ComplianceKnowledgeGraphService   (orchestration + node formatting)
                  ├── ComplianceCorpusQueryService      (resolve release / revision / entity)
                  ├── ComplianceRelationshipResolver    (raw structural navigation)
                  └── ComplianceCrossReferenceService   (cross-framework seam → empty)
                        └── CrossFrameworkMappingProviderInterface  (no implementation)
```

### Service responsibilities

| Service | Responsibility |
| --- | --- |
| `ComplianceRelationshipResolver` | Pure, release-scoped navigation. Returns Eloquent models/collections: parent chains, child controls, requirements, controls of a domain, siblings, and counts. Eager-loads `sourceDocument`. No formatting. |
| `ComplianceCrossReferenceService` | Builds the `cross_references` block. `intra_framework` is empty (relationships are already expressed as ancestors/descendants/siblings). `cross_framework` delegates to an optional provider — empty this sprint. |
| `ComplianceKnowledgeGraphService` | Orchestrator + node formatter. Resolves release/active revision, builds the 4 context responses and the granular ancestor/descendant/sibling capabilities, and emits UUID-only nodes. |
| `ComplianceGraphController` | Workspace HTTP layer: authorization, audit, cache, error mapping, JSON envelope. |

---

## Graph model

```
Framework
  └── Domain            (parent_domain_id → self-hierarchy, usually flat in ECC)
        └── Control     (parent_control_id → control self-hierarchy)
              └── Requirement
```

- **Ancestors** — ordered root → immediate parent (excludes the node itself).
  - Requirement → `[domain, …parent controls, control]`
  - Control → `[domain, …parent controls]`
  - Domain → `[…parent domains]` (empty when top-level)
- **Descendants** — immediate children (one level), each carrying child counts so a consumer can page deeper.
  - Domain → controls
  - Control → child controls + requirements
  - Requirement → `[]` (leaf)
- **Siblings** — same parent / same tree level, excluding the node itself.
  - `getSiblingControls` — controls sharing the same `domain_id` and `parent_control_id`.
  - `getSiblingRequirements` — requirements sharing the same `control_id`.

---

## Relationship model

| Capability | Input | Returns |
| --- | --- | --- |
| `getFrameworkContext` | framework, release | framework node + domain nodes (with counts) + totals |
| `getDomainContext` | + domain code | domain node, ancestors, descendant controls, sibling domains, counts |
| `getControlContext` | + control code | control node, ancestors (domain + parent controls), descendants (child controls + requirements), sibling controls |
| `getRequirementContext` | + requirement code | requirement node, ancestors (domain + controls), siblings, leaf descendants `[]` |
| `getAncestors` | entityType, code | ordered ancestor nodes |
| `getDescendants` | entityType, code | immediate descendant nodes |
| `getSiblingControls` | control code | sibling control nodes |
| `getSiblingRequirements` | requirement code | sibling requirement nodes |

### Node response model (Task 3)

Every graph node is UUID-only and carries:

```json
{
  "entity_type": "control",
  "uuid": "5d2f…",
  "code": "1-1-1",
  "display_code": "1-1-1",
  "normalized_code": "1-1-1",
  "level": 3,
  "control_type": "main",
  "title_en": "…",
  "title_ar": "…",
  "provenance": {
    "source_document_key": "nca-ecc-2-2024",
    "source_reference": "…",
    "source_page": 14,
    "official_reference": "ECC 1-1-1"
  },
  "child_counts": { "child_controls": 0, "requirements": 1 }
}
```

Every context envelope additionally carries `framework`, `release`, `active_revision`, `context_type`, and `generated_at`. **No numeric IDs anywhere.**

---

## API endpoints

Workspace-scoped only. `{project}` accepts both `projects/{project}` and `workspaces/{project}` prefixes.

| Method | Path | Capability |
| --- | --- | --- |
| GET | `/api/workspaces/{project}/compliance/graph/frameworks/{frameworkKey}/releases/{releaseCode}` | framework context |
| GET | `…/releases/{releaseCode}/domains/{domainCode}` | domain context |
| GET | `…/releases/{releaseCode}/controls/{controlCode}` | control context |
| GET | `…/releases/{releaseCode}/requirements/{requirementCode}` | requirement context |

**Middleware applied:** `auth:sanctum` (outer group) → `project.qynshield` (membership + QynShield entitlement) → `throttle:compliance-graph-read` → `ProjectPolicy::view` (in controller) → audit `logGraph` → revision-keyed cache.

### Response envelope

```json
{
  "success": true,
  "data": {
    "context_type": "control_context",
    "framework": { },
    "release": { },
    "active_revision": { },
    "node": { },
    "ancestors": [ ],
    "descendants": [ ],
    "siblings": [ ],
    "cross_references": { "intra_framework": [], "cross_framework": [] },
    "generated_at": "2026-06-25T00:00:00+00:00"
  }
}
```

Errors use `{ "success": false, "message": "…", "code": "corpus_not_found" }` with HTTP 404 for unknown framework/release/domain/control/requirement.

### Examples

```bash
# Control context: ancestors (domain), descendants (requirements), sibling controls
curl -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/workspaces/$PROJECT/compliance/graph/frameworks/nca-ecc/releases/2-2024/controls/1-1-1"

# Requirement context: ancestors (domain + control), sibling requirements
curl -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/workspaces/$PROJECT/compliance/graph/frameworks/nca-ecc/releases/2-2024/requirements/1-1-1-1"
```

---

## Caching & rate limits

- **Cache:** reuses `ComplianceCorpusCacheService` (revision UUID is part of the key), so a new active revision auto-invalidates graph responses. Segments: `graph:framework`, `graph:domain:{code}`, `graph:control:{code}`, `graph:requirement:{code}`.
- **Rate limit:** `compliance-graph-read` — `config('compliance.graph.rate_limits.read.max_attempts')`, default **120/min** per user (env `COMPLIANCE_GRAPH_READ_RATE_LIMIT`).

---

## Future cross-framework mapping model

The seam is shipped, the implementation is not.

- `App\Contracts\Compliance\CrossFrameworkMappingProviderInterface` declares `supportsFramework()` and `mappingsFor()`.
- `ComplianceCrossReferenceService` resolves the provider **only if** one is bound in the container. None is bound this sprint, so `cross_references.cross_framework` is always `[]`.
- A future sprint can bind a provider (e.g. ECC ⇄ ISO 27001 ⇄ NIST CSF) **without changing** the graph services, controller, routes, or response contract. Mappings must be deterministic and UUID-only, and must not perform AI execution.

---

## AI expansion path

This layer is the deterministic "retrieval skeleton" for a later AI phase:

1. **Context expansion** — an AI orchestrator calls `getControlContext` / `getRequirementContext` to ground a prompt with the node + ancestors + siblings, then feeds those UUIDs into the Sprint 6 AI Consumption Contract Layer to fetch full bilingual text + citations.
2. **Traversal planning** — `getDescendants` (with `child_counts`) lets a planner walk a subtree breadth-first without over-fetching.
3. **Cross-framework grounding** — once a mapping provider is bound, `cross_references.cross_framework` supplies equivalent controls across frameworks for comparative answers.
4. **Still no execution here** — vectors/RAG/LLM remain outside this layer; the graph only provides structure and provenance.

---

## Security model

| Control | Mechanism |
| --- | --- |
| Authentication | `auth:sanctum` |
| Workspace membership | `EnsureQynShieldEntitlement` (`project.qynshield`) + `ProjectPolicy::view` |
| Module entitlement | QynShield entitlement check in `project.qynshield` |
| Auditability | `ComplianceCorpusAccessAuditLogger::logGraph` (`action = compliance_graph_access`) |
| Abuse protection | `throttle:compliance-graph-read` |
| Cache correctness | Revision-keyed cache (auto-invalidates on new active revision) |
| Data exposure | UUID-only; corpus content only (no tenant data, no evidence) |

---

## Files changed

**New**
- `backend/app/Contracts/Compliance/CrossFrameworkMappingProviderInterface.php`
- `backend/app/Services/Compliance/Graph/ComplianceRelationshipResolver.php`
- `backend/app/Services/Compliance/Graph/ComplianceCrossReferenceService.php`
- `backend/app/Services/Compliance/Graph/ComplianceKnowledgeGraphService.php`
- `backend/app/Http/Controllers/Compliance/ComplianceGraphController.php`
- `backend/routes/compliance-graph.php`
- `backend/tests/Unit/ComplianceKnowledgeGraphTest.php`
- `docs/QCIF_SPRINT7_KNOWLEDGE_GRAPH.md`

**Modified**
- `backend/routes/api.php` (wire graph routes)
- `backend/app/Services/Compliance/ComplianceCorpusAccessAuditLogger.php` (`logGraph`)
- `backend/config/compliance.php` (`graph.rate_limits`)
- `backend/app/Providers/RouteServiceProvider.php` (`compliance-graph-read` limiter)
