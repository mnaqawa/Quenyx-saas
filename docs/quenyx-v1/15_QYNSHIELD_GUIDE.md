# 15 — QynShield Guide

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.0 |
> | Software Version | v1.0.0 RC1 |
> | Applies To | Quenyx vOPS HUB v1.0.0 RC1 |
> | Classification | Public / External |
> | Owner | Product |
> | Status | Released |
> | Last Updated | 2026-06-29 |
> | Document Type | Module guide |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial v1 pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | Aligned to v1.0.0 RC1; AI referenced as the shared platform layer. |

**Audience:** Compliance users, auditors.
**Status:** 🟢 Backend/API built and exercised; UI surface = executive/demo layer (broader UI is
roadmap). All routes require the **QynShield entitlement** (`project.qynshield`).

---

## 1. QCIF corpus

QynShield is powered by **QCIF** (see Doc 06). The corpus is **official‑source‑only**, immutable, and
provenance‑tracked: authorities → frameworks → releases → domains → controls → requirements, with
source documents and approved revisions. Browse via
`/compliance/corpus/frameworks/{frameworkKey}/releases/{releaseCode}/…` (summary, domains, controls,
search).

## 2. NCA ECC‑2:2024

The shipped framework: authority **NCA**, framework **`nca-ecc`**, release **`2:2024`**, **Revision
v1** active — **5 domains, 108 controls, 108 requirements**, modelled from official source documents.

## 3. Evidence

Evidence Intelligence tracks evidence per requirement/control with **types** and **statuses**
(`compliance/evidence/types`, `…/statuses`, `POST …/evidence/context`). Evidence is the input to gap
assessment.

## 4. Gap assessment

Deterministic, rule‑based correlation of evidence to requirements
(`compliance/gap/summary`, `…/domains`, `…/controls/{code}`, `…/requirements/{code}`,
`POST …/gap/context`). Gap status is **derived by rule** (e.g. missing evidence ⇒ gap), never
guessed.

## 5. Recommendations

Rule‑based recommendations with explicit source rules
(`compliance/recommendations/summary`, `…/controls/{code}`, `…/requirements/{code}`,
`POST …/generate`, `POST …/context`). Each recommendation traces to the rule that produced it
(`ComplianceReasoningRuleSet`).

## 6. Copilot

`POST /compliance/copilot/message` (+ conversations endpoints). The Copilot orchestrates retrieval +
**deterministic reasoning** + (optional) RAG context + prompt → provider, and is
**citation‑enforced**. Default **mock mode**; real‑model mode is operator‑enabled.

## 7. Executive dashboard

Read‑only aggregation for demonstrations/executives:
`compliance/executive/dashboard`, `scorecard`, `timeline`, `explainability`, `platform`. All values
derive from **real engine data** — no fabricated percentages or activity.

## 8. Explainability

`compliance/executive/explainability` answers "why" questions ("Why is this requirement
non‑compliant?", "Why did I receive this recommendation?") from **deterministic reasoning** — every
answer is traceable.

## 9. Citations

QynShield's core principle: **no source, no answer.** Corpus answers and Copilot responses cite the
official source; uncited content is excluded. This is what makes outputs auditable.

## 10. Current API / backend status

🟢 Built and exercised: corpus, graph, mapping, evidence, gap, recommendation, retrieval, copilot,
executive. 🟡 Feature‑flagged: real‑model Copilot, RAG runtime.

## 11. UI status

The current UI surface is the **executive/demonstration** layer + APIs. A full self‑service
compliance workspace UI is **roadmap** (Sprint 20+). Integrators can consume the APIs today (Doc 08).
