# Quenyx vOPS HUB — Documentation Pack v3.0 (v1.0.0 GA)

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 3.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 (GA) |
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
> | 2.0 | 2026-06-29 | v1.0.0 alignment: native QynSight monitoring (Nagios removed as platform dependency), QynCore internal communication, Integrations = external systems only, Quenyx AI as a shared platform layer, roadmap Phases 1–4 / Sprints 20–25, document metadata headers. |
> | 2.1 | 2026-06-29 | v1.0.0 cleanup: code aligned to docs — removed stale gateway Nagios config, normalized native engine_key, reclassified QynIntegrations (entitlement key only) and QynCore (platform core) in code catalogs, banner-marked legacy ShieldObserve docs. |
> | 2.2 | 2026-06-29 | v1.0.0 AI polish: Sprint 20 surface branded **Quenyx AI** (routes unchanged; `/quenyx-ai/*` alias); enterprise provider catalog (14 providers, only OpenAI executable); mock provider removed from production default/UI; real Test‑connection endpoint; enriched overview. Affected PDFs (01,05,07,08,11,12,16,18) should be regenerated on a build host (see Audit Report v1.0.0 AI addendum). |
> | 2.3 | 2026-06-30 | Sprint 21 — **QynSight Operations Intelligence**: QynSight becomes a live AI consumer (Monitoring Copilot, Alert/Root‑Cause/Capacity/Performance/Infrastructure/Service‑Health intelligence, evidence‑based recommendations, OI dashboard) reusing the shared Quenyx AI platform; new UUID‑only `/api/qynsight/intelligence/*` APIs. Docs 01, 02, 05, 07, 08, 11, 12, 14, 18 updated and PDFs regenerated (deterministic CDP builder). |
| 2.4 | 2026-06-30 | Sprint 24 — **Enterprise Knowledge & Collaboration Platform**: QynKnow/QynSupport/QynNotify unified into shared platform capabilities — **Knowledge Source Registry** (registry-driven, Internal KB live + planned providers), **Enterprise Search** (keyword + semantic over real rows), **Knowledge Graph v2**, **Global Timeline**, **Collaboration Platform** (comments/mentions/participants, polymorphic), **Ticket Intelligence**, **Notification Intelligence** — all AI via `ModuleAiNarrator` (no direct provider calls, no duplicated AI/automation/orchestration). New guides 28–32; docs 05, 07, 08, 11, 12, 18, 21 updated; PDFs regenerated (deterministic CDP builder). |
| 3.0 | 2026-06-30 | **Documentation Pack v3.0 — v1.0.0 GA.** Sprint 25 — **Enterprise Intelligence Platform**: **Platform Event Bus** (publish/subscribe, audited, async-ready), **Enterprise Context Engine** (one normalized AI context), **QynVA** Enterprise AI Operator, **QynBalance** Cost Intelligence, **Executive Intelligence**, **Enterprise Analytics**, **Platform Health**; full module navigation enabled (sidebar flag removed). New guides 33–40 and release artifacts 41–44; docs 05, 07, 08, 11, 12, 18, 21 updated; all affected PDFs regenerated (deterministic CDP builder). |
| 3.1 | 2026-06-30 | **GA production-readiness remediation** (Phase 5 certification). Security hardening: configurable CORS allowlist, HTTP security headers (CSP/HSTS/X-Frame-Options/etc.), Sanctum token expiration + daily pruning, login/register rate limiting + timing-attack resistance, post-password-change token revocation. AI hardening: single governed pipeline, prompt-injection defenses, audit logging on the KB path. Platform Event Bus publishers wired across incident/alert/workflow/ticket/knowledge. Performance: KnowledgeGraph N+1 fix, executive read-model dedupe. DevOps: liveness + readiness health endpoints (`/api/health/ready`), verified backup/restore scripts, PHP-FPM deployment guidance, `quenyx:config-check` configuration validation. Frontend: global ErrorBoundary, toast notifications, module icons, navigation/help polish, QynShield kept hidden until production-ready. All RC1 labels removed; status set to GA. No breaking API changes. |

This pack is the **canonical, definitive documentation set** for Quenyx vOPS HUB at **v1.0.0 (GA)**.
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
| 22 | [QynAsset Guide](./22_QYNASSET_GUIDE.md) | [PDF](../pdf/22_QYNASSET_GUIDE.pdf) | QynAsset users | Asset Intelligence module |
| 23 | [AI Adapter Developer Guide](./23_AI_ADAPTER_DEVELOPER_GUIDE.md) | [PDF](../pdf/23_AI_ADAPTER_DEVELOPER_GUIDE.pdf) | Module engineers | Add AI to any module |
| 24 | [Automation Platform Guide](./24_AUTOMATION_PLATFORM_GUIDE.md) | [PDF](../pdf/24_AUTOMATION_PLATFORM_GUIDE.pdf) | Platform engineers, SREs | Shared, registry-driven automation |
| 25 | [QynRun Guide](./25_QYNRUN_GUIDE.md) | [PDF](../pdf/25_QYNRUN_GUIDE.pdf) | QynRun users | Enterprise Automation module |
| 26 | [QynReact Guide](./26_QYNREACT_GUIDE.md) | [PDF](../pdf/26_QYNREACT_GUIDE.pdf) | Incident responders | Unified Incident Workspace |
| 27 | [Incident Response Guide](./27_INCIDENT_RESPONSE_GUIDE.md) | [PDF](../pdf/27_INCIDENT_RESPONSE_GUIDE.pdf) | On-call, SRE | Incident lifecycle playbook |
| 28 | [Enterprise Knowledge Guide](./28_ENTERPRISE_KNOWLEDGE_GUIDE.md) | [PDF](../pdf/28_ENTERPRISE_KNOWLEDGE_GUIDE.pdf) | Knowledge users, platform engineers | Shared Knowledge Platform |
| 29 | [Service Desk Guide](./29_SERVICE_DESK_GUIDE.md) | [PDF](../pdf/29_SERVICE_DESK_GUIDE.pdf) | Service desk, SRE | QynSupport + Ticket Intelligence |
| 30 | [Notification Guide](./30_NOTIFICATION_GUIDE.md) | [PDF](../pdf/30_NOTIFICATION_GUIDE.pdf) | On-call, ops | QynNotify + Notification Intelligence |
| 31 | [Collaboration Guide](./31_COLLABORATION_GUIDE.md) | [PDF](../pdf/31_COLLABORATION_GUIDE.pdf) | All users, platform engineers | Shared collaboration layer |
| 32 | [Global Timeline Guide](./32_GLOBAL_TIMELINE_GUIDE.md) | [PDF](../pdf/32_GLOBAL_TIMELINE_GUIDE.pdf) | Ops, auditors | Platform-wide chronological read-model |
| 33 | [Executive Intelligence Guide](./33_EXECUTIVE_INTELLIGENCE_GUIDE.md) | [PDF](../pdf/33_EXECUTIVE_INTELLIGENCE_GUIDE.pdf) | Execs, ops leaders | Executive Intelligence + Enterprise Analytics |
| 34 | [Context Engine Guide](./34_CONTEXT_ENGINE_GUIDE.md) | [PDF](../pdf/34_CONTEXT_ENGINE_GUIDE.pdf) | Platform/AI engineers | Single normalized AI context |
| 35 | [Platform Event Bus Guide](./35_PLATFORM_EVENT_BUS_GUIDE.md) | [PDF](../pdf/35_PLATFORM_EVENT_BUS_GUIDE.pdf) | Platform engineers | Publish/subscribe domain events |
| 36 | [Platform Operations Guide](./36_PLATFORM_OPERATIONS_GUIDE.md) | [PDF](../pdf/36_PLATFORM_OPERATIONS_GUIDE.pdf) | SRE, platform engineers | Operate the platform itself |
| 37 | [QynVA Guide](./37_QYNVA_GUIDE.md) | [PDF](../pdf/37_QYNVA_GUIDE.pdf) | QynVA users, engineers | Enterprise AI Operator |
| 38 | [QynBalance Guide](./38_QYNBALANCE_GUIDE.md) | [PDF](../pdf/38_QYNBALANCE_GUIDE.pdf) | FinOps, ops leaders | Enterprise Cost Intelligence |
| 39 | [Release Notes v1.0](./39_RELEASE_NOTES_v1.0.md) | [PDF](../pdf/39_RELEASE_NOTES_v1.0.pdf) | Everyone | v1.0.0 GA release notes |
| 40 | [Migration Guide](./40_MIGRATION_GUIDE.md) | [PDF](../pdf/40_MIGRATION_GUIDE.pdf) | DevOps, SRE | v1.0.0 upgrade |
| 41 | [Architecture Summary v1.0](./41_ARCHITECTURE_SUMMARY_v1.0.md) | [PDF](../pdf/41_ARCHITECTURE_SUMMARY_v1.0.pdf) | Architects, execs | Release artifact |
| 42 | [Executive Summary v1.0](./42_EXECUTIVE_SUMMARY_v1.0.md) | [PDF](../pdf/42_EXECUTIVE_SUMMARY_v1.0.pdf) | Execs, board | Release artifact |
| 43 | [Deployment Checklist v1.0](./43_DEPLOYMENT_CHECKLIST_v1.0.md) | [PDF](../pdf/43_DEPLOYMENT_CHECKLIST_v1.0.pdf) | SRE, DevOps | Release artifact |
| 44 | [Production Readiness Report v1.0](./44_PRODUCTION_READINESS_REPORT_v1.0.md) | [PDF](../pdf/44_PRODUCTION_READINESS_REPORT_v1.0.pdf) | Eng leadership | Release artifact |
| QA | [QA Audit Report](./QA_AUDIT_REPORT.md) | — | Eng leadership, auditors | Track‑B audit results |

---

## Platform status at a glance (v1.0.0 GA)

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
| Automation Platform (Sprint 23) — shared, registry-driven Automation Registry / Execution / Workflow / Runbook / Approval engines; Incident Workspace (QynReact); cross-module orchestration | 🟢 Built · 🟡 same AI flags |
| Enterprise Knowledge & Collaboration Platform (Sprint 24) — **Knowledge Source Registry** (Internal KB live; Markdown/PDF/HTML/Git/Confluence/SharePoint/Drive/OneDrive/Wikis/Elastic/Vector planned), **Enterprise Search**, **Knowledge Graph v2**, **Global Timeline**, **Collaboration Platform**, **Ticket Intelligence**, **Notification Intelligence**; all AI via `ModuleAiNarrator`; UUID‑only `/api/qynknow/*`, `/api/qynsupport/*`, `/api/qynnotify/*`, `/api/collaboration/*` | 🟢 Built · live AI consumers · 🟡 same AI flags |
| Enterprise Intelligence Platform (Sprint 25) — **Platform Event Bus** (publish/subscribe, audited, async-ready), **Enterprise Context Engine** (one normalized AI context), **QynVA** Enterprise AI Operator, **QynBalance** Cost Intelligence, **Executive Intelligence**, **Enterprise Analytics**, **Platform Health**; all AI via `ModuleAiNarrator` + Context Engine; UUID-only `/api/qynva/*`, `/api/qynbalance/*` | 🟢 Built · live AI consumers · 🟡 same AI flags |
| Module navigation — full sidebar enabled (QynSight, QynAsset, QynRun, QynReact, QynKnow, QynSupport, QynNotify, QynShield, QynBalance, QynVA); temporary sidebar feature flag removed | 🟢 Enabled |
| QynCore (platform core: internal communication services) | 🟢 Platform layer (not a navigable module) |
| Integrations (platform page, **external** systems only) | 🟢 Platform page (not a module) |

---

## Official roadmap (v1.0.0 GA)

> All business modules (QynSight, QynAsset, QynRun, QynReact, QynKnow, QynSupport, QynNotify, QynShield,
> QynBalance, QynVA) are **enabled in the navigation** at v1.0.0 — the temporary sidebar feature flag has
> been removed. QynCore and Integrations remain platform-only (not customer modules).

| Phase | Name | Status |
|---|---|---|
| **Phase 1** | Platform Foundation | ✅ Completed |
| **Phase 2** | Operations Platform (QynSight) | ✅ Completed |
| **Phase 3** | Compliance & Enterprise AI Foundation (QCIF Sprints 1–19, AI Platform Foundation) | ✅ Completed |
| **Phase 4** | **Enterprise AI Platform** | ✅ Completed (Sprints 20–25 delivered) |

**Phase 4 — Enterprise AI Platform**

| Sprint | Title | Status |
|---|---|---|
| Sprint 20 | Unified AI Workspace | ✅ Delivered |
| Sprint 21 | Operations Intelligence | ✅ Delivered |
| Sprint 22 | Asset Intelligence (QynAsset) | ✅ Delivered |
| Sprint 23 | Enterprise Automation & Incident Intelligence (QynRun/QynReact) | ✅ Delivered |
| Sprint 24 | Enterprise Knowledge & Collaboration Platform (QynKnow/QynSupport/QynNotify) | ✅ Delivered |
| Sprint 25 | Enterprise Intelligence Platform (Event Bus, Context Engine, QynVA, QynBalance, Executive Intelligence, Analytics, Platform Health) | ✅ Delivered (v1.0.0 GA) |

Sprint 25 completes the original Quenyx roadmap. Post-v1.0 candidates (informational, no code yet):
queue-backed async event dispatch by default, connected cloud-billing source for QynBalance, and
additional live knowledge-source providers.

---

## PDF deliverables

Branded, print-ready PDFs of **all 44** documents (01–44) are generated into the single canonical
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
