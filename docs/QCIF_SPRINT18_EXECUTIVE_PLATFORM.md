# QCIF Sprint 18 — Executive Demonstration Platform

> Module: **QynShield** · Status: **Complete** · Nature: **read-only exposure of existing intelligence**

This sprint turns the QCIF backend into an investor-ready and customer-ready demonstration surface.
It creates **no new intelligence** and **no data** — every value comes from the engines already built
(Corpus, Knowledge Graph, Mapping, Evidence, Gap Assessment, Recommendation, Reasoning, Retrieval,
RAG). All endpoints are **read-only, deterministic, UUID-only, workspace-scoped, and AI-free**.

**No fake data. No demo data. No mocked compliance results.**

---

## 1. Architecture

```
                    ┌─────────────────────────────────────────────┐
                    │      Executive Demonstration Platform        │  (read-only aggregation)
                    └─────────────────────────────────────────────┘
                                       │ reuses
   ┌───────────────┬──────────────────┼───────────────────┬────────────────────┐
   ▼               ▼                  ▼                   ▼                    ▼
Gap Assessment  Recommendation   Corpus Query        Reasoning Rules      RAG / Retrieval
   Engine          Engine          Service             (catalog)            (chunk counts)
```

Services (`app/Services/Compliance/Executive/`):

| Service | Responsibility | Reuses |
|---|---|---|
| `ComplianceExecutiveDashboardService` | One aggregated executive view | Gap, Recommendation, Scorecard, Timeline |
| `ComplianceHealthScorecardService` | Deterministic requirement-count scorecard + trend | Gap, Recommendation, gap history |
| `ComplianceExecutiveTimelineService` | Chronological real-event feed | Revisions, imports, evidence lifecycle, gap, recommendations |
| `ComplianceExplainabilityService` | "Why" answers from deterministic rules | Gap, Recommendation |
| `CompliancePlatformMetricsService` | Real investor/platform metrics | DB counts + config |

Everything is composed in `ComplianceExecutiveController`, exposed under
`/{projects|workspaces}/{project}/compliance/executive/*`.

---

## 2. Executive Dashboard (`/executive/dashboard`)

Aggregated, read-only, all values derived from existing services:

- **Framework health** + **workspace health** — rolled-up status from the Gap coverage tree.
- **Domain health** — per-domain status + counts.
- **Gap distribution** — requirement counts by status and by severity.
- **Recommendation priorities** — totals by priority / type from the Recommendation Engine.
- **Scorecard** — the deterministic card set (see §3).
- **Evidence coverage** — tenant evidence counts by lifecycle status + correlation counts.
- **Corpus statistics** — domains / controls / requirements / source documents for the release.
- **Revision information** — active corpus revision (uuid, number, checksum, entity counts).
- **Recent activity** — latest events from the timeline.

---

## 3. Compliance Health Scorecard (`/executive/scorecard`)

**No arbitrary percentages.** The scorecard exposes the discrete, rule-derived counts produced by the
Gap Assessment Engine, plus Recommendation Engine totals:

```
cards: {
  compliant_requirements, partially_compliant, non_compliant,
  no_evidence, evidence_expired, evidence_rejected, evidence_pending,
  not_assessed, recommendations
}
by_status[], by_severity{}, by_recommendation_priority{}, workspace_status, framework_status
trend: { available, source: 'gap_assessment_history', points: [{ assessed_at, requirements, satisfied, gaps }] }
```

Trend support activates automatically where append-only gap-assessment history exists. There is no
score and no probability anywhere.

---

## 4. Executive Timeline (`/executive/timeline`)

A single chronological feed (newest first) of **real** events only:

- `corpus_revision` — revision activations.
- `corpus_import` — import runs (incl. dry-runs).
- `evidence_lifecycle` — evidence state transitions (from→to).
- `gap_assessment` — assessment runs with totals.
- `recommendation_generation` — persisted recommendation batches.

Each event is `{ type, uuid, occurred_at, title_en, title_ar, status?, metadata }`. No fabricated
activity; events come straight from append-only tables.

---

## 5. Copilot Demo Mode (`AI_COPILOT_DEMO_MODE`)

When `AI_COPILOT_DEMO_MODE=true`, every Copilot response gains a `demo` block exposing the
deterministic **business** reasoning behind the answer:

- `reasoning_trace` — the deterministic reasoning graph.
- `rules_fired` — the business rule IDs that fired.
- `findings` + `recommendation_source` (rule_id, action, priority, citations).
- `citations` — corpus + grounding references.
- `retrieved_chunks` — the retrieval chunks considered.
- `evidence_chain` — the deterministic grounding from engine skills.
- `missing_information` + `answer_strategy`.

This is **business reasoning only** — it is NOT chain-of-thought and adds no new reasoning. OFF by
default; the existing Copilot flow is unchanged when disabled.

---

## 6. Explainability (`/executive/explainability`)

`ComplianceExplainabilityService` answers the executive "why" questions strictly from deterministic
engines (no AI):

- **Why is this requirement non-compliant?** → status + the evaluation rule + the reason.
- **Which evidence supports this?** → `evidence_supporting` (approved/valid) + considered + ignored.
- **Which rules fired?** → gap evaluation rule + recommendation source rules.
- **Which citations were used?** → corpus provenance (official_reference / source_reference / page).
- **Why did I receive this recommendation?** (`subject=recommendation`) → source rule, originating
  gap status, rationale, priority basis.

Query params: `code` (requirement code, required), `subject` (`requirement` default | `recommendation`),
optional `framework` / `release`.

---

## 7. Investor Metrics (`/executive/platform`)

`CompliancePlatformMetricsService` exposes **real** counts only:

- **Corpus**: frameworks onboarded, framework releases, active/total corpus revisions, domains,
  controls, requirements, control objectives, guidance items, evidence expectations, evidence types,
  source documents.
- **Knowledge graph**: nodes, edges, objective mappings.
- **Engine capabilities**: AI skills, providers implemented, reasoning rules, reasoning decision
  types, retrieval modes, retrieval chunks indexed, RAG enabled flag.
- **Platform usage**: gap assessments run, recommendations generated.

It **never** fabricates customers, revenue, ROI, or benchmarks (`integrity.fabricated_metrics = false`).

---

## 8. API

All read-only, workspace-scoped, `auth:sanctum` + `ProjectPolicy::view` + `project.qynshield` +
audit logging + `throttle:compliance-executive`:

```
GET /api/workspaces/{project}/compliance/executive/dashboard       ?framework&release
GET /api/workspaces/{project}/compliance/executive/scorecard       ?framework&release
GET /api/workspaces/{project}/compliance/executive/timeline        ?framework&release&limit
GET /api/workspaces/{project}/compliance/executive/explainability  ?code&subject&framework&release
GET /api/workspaces/{project}/compliance/executive/platform
```

(`/projects/...` aliases are registered identically.) Not-found subjects return **404**.

---

## 9. Investor Walkthrough

1. **`/executive/platform`** — show the scale of real intelligence: frameworks, controls,
   requirements, reasoning rules, retrieval chunks, knowledge-graph nodes. No vanity metrics.
2. **`/executive/dashboard`** — show live framework + domain health, gap distribution, and
   remediation priorities derived from the deterministic engines.
3. **Copilot with `AI_COPILOT_DEMO_MODE=true`** — ask a compliance question and reveal the `demo`
   block: rules fired, citations, evidence chain — proving answers are grounded, not hallucinated.

## 10. Customer Walkthrough

1. **`/executive/scorecard`** — the customer sees exactly how many requirements are compliant, lack
   evidence, are expired/rejected, or pending — with trend over time.
2. **`/executive/timeline`** — the audit-grade chronological history of corpus, evidence, and
   assessment activity.
3. **`/executive/explainability?code=...`** — for any requirement: why it is non-compliant, which
   evidence supports it, which rules fired, which citations were used, and what to do next.

---

## 11. Future Roadmap

- Front-end dashboards/Canvas visualizations on top of these read-only APIs (no backend change).
- Scheduled gap-assessment snapshots to enrich scorecard trends.
- Exportable executive PDF/board packs (still derived from these deterministic payloads).
- Multi-framework executive rollups once additional corpora are onboarded.

The platform deliberately stays a thin, deterministic exposure layer — all intelligence remains in
the QCIF engines.

---

## 12. Security & Integrity

- Read-only; no writes, no side effects.
- UUID-only (no numeric IDs leak).
- Deterministic; identical inputs → identical outputs.
- No AI is required or invoked for any executive endpoint.
- No fabricated metrics; every value is a live count or a real engine result.
- Workspace-scoped with QynShield entitlement + audit logging + rate limiting.
