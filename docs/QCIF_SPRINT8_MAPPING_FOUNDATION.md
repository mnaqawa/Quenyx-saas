# QCIF Sprint 8 — Cross-Framework Mapping Foundation

**Phase:** Mapping architecture (no AI execution, no framework imports)
**Scope:** The deterministic foundation future framework mappings will use, built on control objectives.
**Out of scope (explicitly NOT in this sprint):** UI, AI, vectors, RAG, assessments, scoring, framework imports, any actual cross-framework mapping data.

---

## Purpose

QCIF Foundation v1 is complete (NCA ECC-2:2024, Revision v1 — 5 domains / 108 controls /
108 requirements; corpus, revisioning, provenance, citations, corpus APIs, workspace access,
AI Contract Layer, Knowledge Graph Layer). No AI execution exists.

Sprint 8 builds the **Cross-Framework Mapping Foundation**: the architecture, services, API
shape, and contracts that future frameworks (ISO 27001, SAMA, CST, PDPL, SOC 2) will plug
into — **without** importing any new framework and **without** fabricating a single mapping.

The pivot is the **control objective**: a global, framework-agnostic anchor. Controls in any
framework that realize the same objective are *related*. Today only NCA ECC exists, so
cross-framework results are deterministically **empty**; the contract is what ships.

---

## What this is / what this is NOT

| This IS | This is NOT |
| --- | --- |
| A deterministic mapping foundation built on control objectives | An AI / RAG / vector / embedding feature |
| UUID-only responses with provenance + confidence basis | A numeric similarity score engine |
| Empty results where no data exists | Fabricated or hardcoded framework relationships |
| Future-framework contracts (interfaces + DTO) | Any ISO/SAMA/CST/PDPL/SOC 2 implementation or data |
| Workspace-scoped, QynShield-gated, audited, cached | A framework import or corpus mutation |

---

## TASK 1 — Control objective foundation audit

Audited `compliance_control_objectives` and `compliance_control_objective_mappings`.

| Property | `compliance_control_objectives` | `compliance_control_objective_mappings` (before) |
| --- | --- | --- |
| **UUID support** | ✅ `uuid` unique | ✅ `uuid` unique |
| **Release awareness** | ⚠️ None — **intentional** (objectives are global cross-framework anchors) | ❌ None (only implicit via `control_id`) |
| **Provenance** | ⚠️ `source_reference` only | ⚠️ `source_reference` only |
| **Revision awareness** | ❌ None | ❌ None |
| **Confidence model** | n/a | ⚠️ legacy `confidence` magnitude string (default `high`) — not a basis |

**Decision (migrate only if required):** objectives are correctly global → **no change**. The
mappings table genuinely could not represent the foundation contract (release/revision
awareness, full provenance, and a confidence *basis* of official/manual/derived), so **one
additive, non-destructive migration** was added:

`2026_06_25_000000_qcif_sprint_8_mapping_foundation.php` adds to
`compliance_control_objective_mappings` (all nullable, guarded by `hasColumn`):

- `framework_release_id` (FK) — release awareness
- `corpus_revision_id` (FK) — revision awareness
- `source_document_id` (FK), `source_page`, `official_reference` — full provenance
- `confidence_basis` (`official|manual|derived`) — Task 4 confidence model
- indexes on `framework_release_id` and `confidence_basis`

The legacy `confidence` column is left untouched (backward compatible); the foundation reads
`confidence_basis`.

---

## TASK 2 — Services

| Service | Responsibility |
| --- | --- |
| `ComplianceControlObjectiveResolver` | Discovers control ⇄ objective links and their **confidence basis** (corpus-native → official; curated mapping row → its basis; shared-objective relation → derived). Returns models + meta, no formatting. |
| `ComplianceMappingService` | Orchestrates the objective catalog, objective mapping, and control mapping responses; resolves framework/release/revision context; emits UUID-only nodes. |
| `ComplianceFrameworkComparisonService` | Framework coverage (objective coverage stats) and framework comparison (intersect objectives across two frameworks). Returns empty where a target framework is not onboarded. |

---

## TASK 3 — Capabilities

| Capability | Where | Behavior today (NCA ECC only) |
| --- | --- | --- |
| `getControlObjectives()` | MappingService | Global objective catalog + per-objective mapped-control count |
| `getControlsForObjective()` | ObjectiveResolver → MappingService (`getObjectiveMapping`) | Controls realizing an objective, with confidence basis |
| `getRelatedControls()` | ObjectiveResolver → MappingService (`getControlMapping`) | Controls sharing an objective (derived); cross-framework empty |
| `getFrameworkCoverage()` | ComparisonService | Coverage stats for the framework's active release |
| `getFrameworkComparison()` | ComparisonService | Empty (no second framework exists yet) — no fakes |

All return **empty where data does not exist**. No fabricated mappings.

---

## TASK 4 — Mapping response model

Every mapping response carries a consistent envelope:

```json
{
  "success": true,
  "data": {
    "context_type": "control_mapping",
    "framework": { },
    "release": { },
    "revision": { },
    "objective": { },
    "source_control": { "...": "...", "objectives": [ ] },
    "related_controls": { "intra_framework": [ ], "cross_framework": [ ] },
    "generated_at": "2026-06-25T00:00:00+00:00"
  }
}
```

- **UUIDs and codes only** — no numeric IDs.
- **provenance** on every node (`source_document_key`, `source_reference`, `source_page`,
  `official_reference`; objectives carry `source_reference`).
- **confidence** is a BASIS — exactly one of `official`, `manual`, `derived`. **No numeric
  scores anywhere.**

### Confidence model

| Basis | Meaning | Source today |
| --- | --- | --- |
| `official` | Asserted by the official corpus | A control's native objective assignment (`controls.control_objective_id`) imported from the regulator |
| `manual` | Curated by a human reviewer | A published `compliance_control_objective_mappings` row |
| `derived` | Computed by QCIF | Two controls share an objective → "related" |

---

## TASK 5 — API endpoints

Workspace-scoped only (`{project}` accepts `projects/{project}` and `workspaces/{project}`).

| Method | Path | Capability |
| --- | --- | --- |
| GET | `/api/workspaces/{project}/compliance/mappings/objectives` | objective catalog |
| GET | `…/mappings/objectives/{objectiveCode}` | controls for an objective |
| GET | `…/mappings/controls/{controlCode}` | objectives + related controls for a control |
| GET | `…/mappings/frameworks/{frameworkKey}/coverage` | objective coverage for a framework |
| GET | `…/mappings/frameworks/compare?source=&target=` | cross-framework comparison |

Optional query params: `?framework=&release=` (context for objective/control endpoints),
`?release=` (coverage), `?source=&target=&source_release=&target_release=` (compare).

**Middleware:** `auth:sanctum` → `project.qynshield` (membership + QynShield) →
`throttle:compliance-mapping-read` → `ProjectPolicy::view` (controller) → audit
`logMapping` (`action = compliance_mapping_access`) → revision-keyed cache.

### Examples

```bash
# Objective catalog
curl -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/workspaces/$PROJECT/compliance/mappings/objectives"

# Related controls for a control (derived via shared objectives; cross_framework empty)
curl -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/workspaces/$PROJECT/compliance/mappings/controls/1-1-1"

# Framework coverage
curl -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/workspaces/$PROJECT/compliance/mappings/frameworks/nca-ecc/coverage"

# Comparison (target not onboarded → empty, with note)
curl -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/workspaces/$PROJECT/compliance/mappings/frameworks/compare?source=nca-ecc&target=iso-27001"
```

---

## TASK 6 — Future mapping contract

Interfaces + a DTO only. **No implementations, no bound providers.**

- `App\Contracts\Compliance\Mapping\FrameworkMappingProviderInterface` — base contract,
  extends the Sprint 7 `CrossFrameworkMappingProviderInterface` so the Graph and Mapping
  layers share one provider abstraction. Declares `frameworkKey()` and `controlMappings()`.
- Per-framework marker interfaces (no methods added):
  `Iso27001MappingProviderInterface`, `SamaMappingProviderInterface`,
  `CstMappingProviderInterface`, `PdplMappingProviderInterface`,
  `Soc2MappingProviderInterface`.
- `App\DataTransferObjects\Compliance\CrossFrameworkControlMapping` — canonical, readonly,
  UUID-only mapping record (`source`, `target`, `objective_code`, `mapping_type`,
  `confidence`, `provenance`). QCIF produces **no instances** this sprint.

A future sprint binds a concrete provider per framework — graph services, mapping services,
controller, routes, and the response contract stay unchanged.

---

## Objective model

Control objectives are the **global anchor**: they have no `framework_release_id` by design.
A control links to objectives two ways — the corpus-native `controls.control_objective_id`
(official) and curated `compliance_control_objective_mappings` rows (manual). Because the
anchor is global, the same objective can be realized by controls in *different* frameworks —
which is exactly how cross-framework relationships will be computed (no per-pair hardcoding).

## Mapping model

```
Control (Framework A)  ──official/manual──►  Control Objective  ◄──official/manual──  Control (Framework B)
                                   │
                                   └── derived ──►  "related controls" (share an objective)
```

## Comparison model

`getFrameworkComparison(source, target)` intersects the objectives referenced by each
framework's controls and emits, per shared objective, the source controls and target controls
(confidence `derived`). With one framework onboarded, the intersection is empty and the
response carries a clear `note` — never fabricated pairs.

## Future framework strategy

1. Onboard the framework's corpus through the existing import pipeline → Revision v1.
2. Ensure its controls carry objective assignments (official) and/or curate mapping rows
   (manual) against the **global** objective catalog (extending it if the new framework
   introduces objectives the catalog lacks).
3. Optionally bind a `*MappingProviderInterface` implementation for direct control↔control
   mappings that aren't objective-mediated.
4. The comparison/coverage/related endpoints immediately return real cross-framework data —
   no API or service changes required.

---

## Security model

| Control | Mechanism |
| --- | --- |
| Authentication | `auth:sanctum` |
| Workspace membership | `ProjectPolicy::view` |
| Module entitlement | `project.qynshield` |
| Auditability | `ComplianceCorpusAccessAuditLogger::logMapping` (`compliance_mapping_access`) |
| Abuse protection | `throttle:compliance-mapping-read` (default 120/min, `COMPLIANCE_MAPPING_READ_RATE_LIMIT`) |
| Cache correctness | Revision-keyed cache (auto-invalidates on new active revision) |
| Data exposure | UUID-only; corpus reference data only (no tenant data, no evidence) |

---

## Files changed

**New**
- `backend/database/migrations/2026_06_25_000000_qcif_sprint_8_mapping_foundation.php`
- `backend/app/Enums/Compliance/MappingConfidence.php`
- `backend/app/Contracts/Compliance/Mapping/FrameworkMappingProviderInterface.php`
- `backend/app/Contracts/Compliance/Mapping/{Iso27001,Sama,Cst,Pdpl,Soc2}MappingProviderInterface.php`
- `backend/app/DataTransferObjects/Compliance/CrossFrameworkControlMapping.php`
- `backend/app/Services/Compliance/Mapping/ComplianceControlObjectiveResolver.php`
- `backend/app/Services/Compliance/Mapping/ComplianceMappingService.php`
- `backend/app/Services/Compliance/Mapping/ComplianceFrameworkComparisonService.php`
- `backend/app/Http/Controllers/Compliance/ComplianceMappingController.php`
- `backend/routes/compliance-mappings.php`
- `backend/tests/Unit/ComplianceMappingFoundationTest.php`
- `docs/QCIF_SPRINT8_MAPPING_FOUNDATION.md`

**Modified**
- `backend/app/Models/Compliance/ComplianceControlObjectiveMapping.php` (new columns, casts, relations)
- `backend/routes/api.php` (wire mapping routes)
- `backend/app/Services/Compliance/ComplianceCorpusAccessAuditLogger.php` (`logMapping`)
- `backend/config/compliance.php` (`mappings.rate_limits`)
- `backend/app/Providers/RouteServiceProvider.php` (`compliance-mapping-read` limiter)
