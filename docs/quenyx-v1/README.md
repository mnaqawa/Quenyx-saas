# Quenyx vOPS HUB — Documentation Pack v1

This pack is the **canonical documentation set** for Quenyx vOPS HUB at the close of **Phase I
(through Sprint 19)**. Every statement here is grounded in the **current codebase and actually
delivered features**. Where something is not yet built, it is labelled as roadmap.

> **No fabrication policy.** These documents do **not** invent customers, revenue, TAM/SAM/SOM,
> benchmarks, certifications, production metrics, unimplemented integrations, or AI capabilities
> that do not exist.

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

| # | Document | Audience | Primary status focus |
|---|---|---|---|
| — | [README.md](./README.md) | Everyone | Index + legend |
| 01 | [Executive Overview](./01_EXECUTIVE_OVERVIEW.md) | Investors, board, execs, partners | Vision + current status |
| 02 | [Product Brochure](./02_PRODUCT_BROCHURE.md) | Customers, sales, pre‑sales | Modules + benefits |
| 03 | [Investor Deck Outline](./03_INVESTOR_DECK_OUTLINE.md) | Investors | 25–35 slide outline |
| 04 | [Executive Whitepaper](./04_EXECUTIVE_WHITEPAPER.md) | CIO/CTO/CISO/board | Concept + governance |
| 05 | [Platform Architecture Bible](./05_PLATFORM_ARCHITECTURE_BIBLE.md) | Architects, seniors, auditors | Full architecture |
| 06 | [QCIF Architecture Bible](./06_QCIF_ARCHITECTURE_BIBLE.md) | Architects, auditors | Compliance engine |
| 07 | [AI Platform Bible](./07_AI_PLATFORM_BIBLE.md) | Architects, AI engineers | AI runtime |
| 08 | [API Reference](./08_API_REFERENCE.md) | Engineers, integrators | Endpoints |
| 09 | [Database Reference](./09_DATABASE_REFERENCE.md) | Engineers, DBAs, auditors | Schema |
| 10 | [Deployment Guide](./10_DEPLOYMENT_GUIDE.md) | DevOps, implementation | Install + ops |
| 11 | [Developer Guide](./11_DEVELOPER_GUIDE.md) | New engineers | Patterns + DoD |
| 12 | [Administrator Guide](./12_ADMINISTRATOR_GUIDE.md) | Admins | Day‑to‑day admin |
| 13 | [Customer User Guide](./13_CUSTOMER_USER_GUIDE.md) | End users | Using the product |
| 14 | [QynSight Guide](./14_QYNSIGHT_GUIDE.md) | QynSight users | Monitoring module |
| 15 | [QynShield Guide](./15_QYNSHIELD_GUIDE.md) | Compliance users | QCIF module |
| 16 | [AI User Guide](./16_AI_USER_GUIDE.md) | All users | Using Quenyx AI |
| 17 | [Implementation Guide](./17_IMPLEMENTATION_GUIDE.md) | Implementation partners | Onboarding |
| 18 | [Operations Runbook](./18_OPERATIONS_RUNBOOK.md) | Ops/SRE | Run + recover |
| 19 | [Security Whitepaper](./19_SECURITY_WHITEPAPER.md) | Security, vCISO, auditors | Security model |
| 20 | [Compliance Whitepaper](./20_COMPLIANCE_WHITEPAPER.md) | Compliance, auditors | Determinism + provenance |
| 21 | [Engineering Principles & Standards](./21_ENGINEERING_PRINCIPLES_AND_STANDARDS.md) | Engineering org | How we build |
| QA | [QA Audit Report](./QA_AUDIT_REPORT.md) | Eng leadership, auditors | Track‑B audit results |

---

## Platform status at a glance (Phase I / Sprint 19)

| Capability | Status |
|---|---|
| QynSight v1.0 (monitoring, infra map, performance, capacity, alerts, service checks, agents) | 🟢 Production‑ready, **feature‑frozen** |
| QCIF Corpus Engine + NCA ECC‑2:2024 Revision v1 | 🟢 Built (backend/API) |
| Knowledge Graph, Cross‑Framework Mapping | 🟢 Built (backend/API) |
| Evidence Intelligence, Gap Assessment, Recommendation Engine | 🟢 Built (backend/API) |
| Deterministic Reasoning Engine | 🟢 Built |
| Executive Demonstration Platform (dashboard/scorecard/timeline/explainability/platform) | 🟢 Built (read‑only) |
| Compliance Copilot v0 (mock mode) | 🟢 Built · 🟡 real‑model mode flag‑gated |
| Retrieval / RAG Foundation | 🟡 Feature‑flagged (metadata‑only; deterministic fallback) |
| Quenyx AI Platform (provider registry, skills, adapters, capability catalog) | 🟢 Built · QynShield adapter live |
| QynSight AI adapter | 🔵 Architecture‑ready (interface only) |
| Other HUB modules (QynRun, QynAsset, QynKnow, …) | 🔵 Registered internally · UI hidden by flag · ⚪ product roadmap |

---

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
