# Quenyx vOPS HUB — Documentation Pack v2.0 (RC1 Alignment)

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.3 |
> | Software Version | v1.0.0 RC1 |
> | Applies To | Quenyx vOPS HUB v1.0.0 RC1 |
> | Classification | Internal |
> | Owner | Platform Engineering |
> | Status | Released |
> | Last Updated | 2026-06-30 |
> | Document Type | Documentation index |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial canonical pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | RC1 alignment: native QynSight monitoring (Nagios removed as platform dependency), QynCore internal communication, Integrations = external systems only, Quenyx AI as a shared platform layer, roadmap Phases 1–4 / Sprints 20–25, document metadata headers. |
> | 2.1 | 2026-06-29 | RC1.1 cleanup: code aligned to docs — removed stale gateway Nagios config, normalized native engine_key, reclassified QynIntegrations (entitlement key only) and QynCore (platform core) in code catalogs, banner-marked legacy ShieldObserve docs. |
> | 2.2 | 2026-06-29 | RC1.1 AI polish: Sprint 20 surface branded **Quenyx AI** (routes unchanged; `/quenyx-ai/*` alias); enterprise provider catalog (14 providers, only OpenAI executable); mock provider removed from production default/UI; real Test‑connection endpoint; enriched overview. Affected PDFs (01,05,07,08,11,12,16,18) should be regenerated on a build host (see Audit Report RC1.1 AI addendum). |
> | 2.3 | 2026-06-30 | Sprint 21 — **QynSight Operations Intelligence**: QynSight becomes a live AI consumer (Monitoring Copilot, Alert/Root‑Cause/Capacity/Performance/Infrastructure/Service‑Health intelligence, evidence‑based recommendations, OI dashboard) reusing the shared Quenyx AI platform; new UUID‑only `/api/qynsight/intelligence/*` APIs. Docs 01, 02, 05, 07, 08, 11, 12, 14, 18 updated and PDFs regenerated (deterministic CDP builder). |

This pack is the **canonical, definitive documentation set** for Quenyx vOPS HUB at **v1.0.0 RC1**.
Every statement here is grounded in the **current production codebase and actually delivered
features**. Where something is not yet built, it is labelled as roadmap.

> **No fabrication policy.** These documents do **not** invent customers, revenue, TAM/SAM/SOM,
> benchmarks, certifications, production metrics, unimplemented integrations, or AI capabilities
> that do not exist.

> **Architecture invariants (read first).**
> - **Monitoring is native.** QynSight runs its own engines — Discovery, Monitoring, Metrics,
>   Service Checks, Alert Engine, Capacity Planning, Analytics, and Infrastructure Map. **Nagios is
>   not a platform dependency**; it may appear only as a legacy migration source or as an optional
>   third‑party integration via the Integrations page.
> - **Internal communication is platform‑native.** Modules communicate through **QynCore** platform
>   services (Platform Event Bus, Shared Services, Module Registry, Service Registry, AI Context
>   Broker, Permission Broker, Audit Pipeline, Notification Broker, Workspace Context, Domain
>   Events) — **never** via HTTP, webhooks, or the Integrations page.
> - **Integrations is a platform page** for **external** systems only (cloud, identity, ITSM,
>   security tooling, messaging). There is no `QynIntegrations` business module.
> - **Quenyx AI is a shared platform layer** across the whole HUB — it is **not** "QynShield AI".

---

## Status legend (built vs roadmap)

| Badge | Meaning |
|---|---|
| 🟢 **Built / Production‑ready** | Implemented, on by default, and exercised by delivered sprints. |
| 🟡 **Built but feature‑flagged** | Code exists but is **off by default** behind an env flag. |
| 🔵 **Architecture‑ready** | Contracts/registries/placeholders exist; no implementation yet. |
| ⚪ **Future roadmap** | Planned only; no code yet. |

---

## Document index

Each document has a Markdown source (editable source of truth) and a branded PDF under
[`docs/pdf/`](../pdf/).

| # | Document | PDF | Audience | Primary status focus |
|---|---|---|---|---|
| — | [README.md](./README.md) | — | Everyone | Index + legend |
| 01 | [Executive Overview](./01_EXECUTIVE_OVERVIEW.md) | [PDF](../pdf/01_EXECUTIVE_OVERVIEW.pdf) | Investors, board, execs, partners | Vision + current status |
| 02 | [Product Brochure](./02_PRODUCT_BROCHURE.md) | [PDF](../pdf/02_PRODUCT_BROCHURE.pdf) | Customers, sales, pre‑sales | Modules + benefits |
| 03 | [Investor Deck Outline](./03_INVESTOR_DECK_OUTLINE.md) | [PDF](../pdf/03_INVESTOR_DECK_OUTLINE.pdf) | Investors | 25–35 slide outline |
| 04 | [Executive Whitepaper](./04_EXECUTIVE_WHITEPAPER.md) | [PDF](../pdf/04_EXECUTIVE_WHITEPAPER.pdf) | CIO/CTO/CISO/board | Concept + governance |
| 05 | [Platform Architecture Bible](./05_PLATFORM_ARCHITECTURE_BIBLE.md) | [PDF](../pdf/05_PLATFORM_ARCHITECTURE_BIBLE.pdf) | Architects, seniors, auditors | Full architecture |
| 06 | [QCIF Architecture Bible](./06_QCIF_ARCHITECTURE_BIBLE.md) | [PDF](../pdf/06_QCIF_ARCHITECTURE_BIBLE.pdf) | Architects, auditors | Compliance engine |
| 07 | [AI Platform Bible](./07_AI_PLATFORM_BIBLE.md) | [PDF](../pdf/07_AI_PLATFORM_BIBLE.pdf) | Architects, AI engineers | AI runtime |
| 08 | [API Reference](./08_API_REFERENCE.md) | [PDF](../pdf/08_API_REFERENCE.pdf) | Engineers, integrators | Endpoints |
| 09 | [Database Reference](./09_DATABASE_REFERENCE.md) | [PDF](../pdf/09_DATABASE_REFERENCE.pdf) | Engineers, DBAs, auditors | Schema |
| 10 | [Deployment Guide](./10_DEPLOYMENT_GUIDE.md) | [PDF](../pdf/10_DEPLOYMENT_GUIDE.pdf) | DevOps, implementation | Install + ops |
| 11 | [Developer Guide](./11_DEVELOPER_GUIDE.md) | [PDF](../pdf/11_DEVELOPER_GUIDE.pdf) | New engineers | Patterns + DoD |
| 12 | [Administrator Guide](./12_ADMINISTRATOR_GUIDE.md) | [PDF](../pdf/12_ADMINISTRATOR_GUIDE.pdf) | Admins | Day‑to‑day admin |
| 13 | [Customer User Guide](./13_CUSTOMER_USER_GUIDE.md) | [PDF](../pdf/13_CUSTOMER_USER_GUIDE.pdf) | End users | Using the product |
| 14 | [QynSight Guide](./14_QYNSIGHT_GUIDE.md) | [PDF](../pdf/14_QYNSIGHT_GUIDE.pdf) | QynSight users | Monitoring module |
| 15 | [QynShield Guide](./15_QYNSHIELD_GUIDE.md) | [PDF](../pdf/15_QYNSHIELD_GUIDE.pdf) | Compliance users | QCIF module |
| 16 | [AI User Guide](./16_AI_USER_GUIDE.md) | [PDF](../pdf/16_AI_USER_GUIDE.pdf) | All users | Using Quenyx AI |
| 17 | [Implementation Guide](./17_IMPLEMENTATION_GUIDE.md) | [PDF](../pdf/17_IMPLEMENTATION_GUIDE.pdf) | Implementation partners | Onboarding |
| 18 | [Operations Runbook](./18_OPERATIONS_RUNBOOK.md) | [PDF](../pdf/18_OPERATIONS_RUNBOOK.pdf) | Ops/SRE | Run + recover |
| 19 | [Security Whitepaper](./19_SECURITY_WHITEPAPER.md) | [PDF](../pdf/19_SECURITY_WHITEPAPER.pdf) | Security, vCISO, auditors | Security model |
| 20 | [Compliance Whitepaper](./20_COMPLIANCE_WHITEPAPER.md) | [PDF](../pdf/20_COMPLIANCE_WHITEPAPER.pdf) | Compliance, auditors | Determinism + provenance |
| 21 | [Engineering Principles & Standards](./21_ENGINEERING_PRINCIPLES_AND_STANDARDS.md) | [PDF](../pdf/21_ENGINEERING_PRINCIPLES_AND_STANDARDS.pdf) | Engineering org | How we build |
| QA | [QA Audit Report](./QA_AUDIT_REPORT.md) | — | Eng leadership, auditors | Track‑B audit results |

---

## Platform status at a glance (v1.0.0 RC1)

| Capability | Status |
|---|---|
| QynSight v1.0 — **native** monitoring engines (Discovery, Monitoring, Metrics, Service Checks, Alert Engine, Capacity Planning, Analytics, Infrastructure Map) + agents | 🟢 Production‑ready, **feature‑frozen** |
| QCIF Corpus Engine + NCA ECC‑2:2024 Revision v1 | 🟢 Built (backend/API) |
| Knowledge Graph, Cross‑Framework Mapping | 🟢 Built (backend/API) |
| Evidence Intelligence, Gap Assessment, Recommendation Engine | 🟢 Built (backend/API) |
| Deterministic Reasoning Engine | 🟢 Built |
| Executive Demonstration Platform (dashboard/scorecard/timeline/explainability/platform) | 🟢 Built (read‑only) |
| Compliance Copilot v0 (mock mode) | 🟢 Built · 🟡 real‑model mode flag‑gated |
| Retrieval / RAG Foundation | 🟡 Feature‑flagged (metadata‑only; deterministic fallback) |
| Quenyx AI Platform (shared, platform‑wide: provider abstraction, skills, context, retrieval, reasoning, knowledge graph, gap/evidence/recommendation engines, copilot, RAG runtime, executive platform, capability catalog) | 🟢 Built · QynShield adapter live |
| Unified AI Workspace (Sprint 20) — top‑level platform AI surface, workspace‑scoped, UUID‑only APIs | 🟢 Built · 🟡 master switch (`AI_WORKSPACE_ENABLED`) |
| QynSight Operations Intelligence (Sprint 21) — Monitoring Copilot, alert/root‑cause/capacity/performance/infrastructure/service‑health intelligence, evidence‑based recommendations, OI dashboard; reuses the shared AI platform; UUID‑only `/api/qynsight/intelligence/*` | 🟢 Built · live AI consumer · 🟡 same AI flags |
| Other HUB modules (QynAsset, QynRun, QynKnow, QynNotify, QynReact, QynVA, QynSupport, QynBalance) | 🔵 Registered internally · **disabled in the navigation by sidebar flag** until production rollout |
| QynCore (platform core: internal communication services) | 🟢 Platform layer (not a navigable module) |
| Integrations (platform page, **external** systems only) | 🟢 Platform page (not a module) |

---

## Official roadmap (v1.0.0 RC1)

> Hidden business modules (QynAsset, QynRun, QynKnow, QynNotify, QynReact, QynVA, QynSupport,
> QynBalance) already exist as registered platform modules; they are **disabled in the navigation by
> a sidebar feature flag only** and are restored by flipping that flag. `platformRegistry.ts` is
> untouched. They are **not** "unavailable" — they are switched off in the UI until production
> rollout.

| Phase | Name | Status |
|---|---|---|
| **Phase 1** | Platform Foundation | ✅ Completed |
| **Phase 2** | Operations Platform (QynSight) | ✅ Completed |
| **Phase 3** | Compliance & Enterprise AI Foundation (QCIF Sprints 1–19, AI Platform Foundation) | ✅ Completed |
| **Phase 4** | **Enterprise AI Platform** | 🟡 In progress (Sprints 20–21 delivered) |

**Phase 4 — Enterprise AI Platform**

| Sprint | Title | Status |
|---|---|---|
| Sprint 20 | Unified AI Workspace | ✅ Delivered in RC1 |
| Sprint 21 | Operations Intelligence | ✅ Delivered in RC1 |
| Sprint 22 | Asset & Knowledge Intelligence | Planned |
| Sprint 23 | Automation & Response Intelligence | Planned |
| Sprint 24 | Service, Notification & Cost Intelligence | Planned |
| Sprint 25 | Enterprise Intelligence Platform | Planned |

There is **no roadmap content beyond Sprint 25** at this time.

---

## PDF deliverables

Branded, print-ready PDFs of **all 21** documents (01–21) are generated into the single canonical
folder [`docs/pdf/`](../pdf/) (title page, auto TOC, page numbers, classification footer, rendered
Mermaid diagrams). Markdown remains the editable source of truth; the PDFs are build artifacts.
Regenerate all of them with the **deterministic CDP builder** (waits for Paged.js/Mermaid pagination
to settle, so long documents are never truncated):

```bash
powershell -File scripts/docs/build-pdfs-cdp.ps1            # all docs
powershell -File scripts/docs/build-pdfs-cdp.ps1 07_AI_PLATFORM_BIBLE 08_API_REFERENCE   # subset
```

The script renders every Markdown source to `docs/pdf/<NN_NAME>.pdf`. The full alignment summary is in
[`../DOCUMENTATION_AUDIT_REPORT_v2.md`](../DOCUMENTATION_AUDIT_REPORT_v2.md).

## How to keep these docs updated

1. **Source of truth is the code.** When a sprint changes routes, config flags, migrations, or
   service behavior, update the affected document(s) in the **same PR**.
2. **Respect the status legend.** Never promote something to 🟢 until it is on by default and
   delivered. New flag‑gated work is 🟡; contracts without implementations are 🔵.
3. **Regenerate the mechanical references.** `08_API_REFERENCE.md` is derived from
   `php artisan route:list`; `09_DATABASE_REFERENCE.md` from `database/migrations`. Re‑derive them
   when routes or migrations change.
4. **No fabrication.** Do not add metrics, customers, or capabilities that are not in the codebase.
5. **Re‑run Track B** ([QA_AUDIT_REPORT.md](./QA_AUDIT_REPORT.md)) at each phase boundary and update
   the verdict.
