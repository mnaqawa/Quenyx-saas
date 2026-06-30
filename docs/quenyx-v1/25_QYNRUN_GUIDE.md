# 25 — QynRun Guide (Enterprise Automation)

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 1.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 |
> | Classification | Confidential — Product |
> | Owner | QynRun Engineering |
> | Status | Released |
> | Last Updated | 2026-06-30 |
> | Document Type | Module guide |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026-06-30 | Initial QynRun guide (Sprint 23): automation workflows, runbooks, executions, approvals, learning, and AI-assisted runbook drafting on the shared Automation Platform. |

**Audience:** Operators and SREs using QynRun.
**Scope:** QynRun — the operator surface over the shared **Automation Platform** (see guide 24) and the **third production AI adapter** on the Quenyx AI Platform.

---

## 1. What QynRun is

QynRun lets you **author, run, approve, and learn from automation** — safely. It consumes the shared Automation Platform, so its execution, approval, rollback, audit, and learning are platform capabilities, not QynRun-specific code.

UI: `/app/workspaces/:id/qynrun/automation` with tabs: **Overview, Library, Workflows, Runbooks, Executions, Approvals, Learning**, plus the contextual **✨ Automation Copilot**.

## 2. Library (registry-driven)

The **Library** tab shows the live **execution adapters** (with `operational`/`planned` state) and the **action catalog** discovered from the registry. Destructive actions are clearly marked *Requires approval*. Nothing here is hardcoded in the UI — it reflects whatever is registered.

## 3. Workflows

A workflow is a data-only definition: **trigger → conditions → actions → approval → execution → verification → notification → audit**, with trigger types `manual | scheduled | event | api`. Running a workflow dispatches its actions through the shared Execution Engine (dry-run by default; live runs require approval per action).

## 4. Runbooks (incl. AI-assisted drafting)

Runbooks are named, ordered step sets. **Draft Runbook** asks Quenyx AI to draft an **editable** runbook for a problem ("High CPU", "Disk Full", "Apache Down", "Database Latency", "Service Failure", "VPN Failure"). The draft is diagnostic-first, flags destructive steps as requiring approval, and is **never auto-executed** — you review, edit, and save it.

## 5. Executions, approvals, rollback

- **Executions** lists every run with status (`dry_run`, `succeeded`, `failed`, `skipped`, `rolled_back`, …) and mode.
- **Approvals** lists live actions awaiting a decision; approving runs them, rejecting cancels them. Requires `administerAi`.
- **Rollback** is available on a succeeded, rollback-capable execution.

## 6. Automation Learning

The **Learning** tab shows aggregated, auditable outcomes per action (success rate, average duration). These feed AI recommendations — **no self-training**, everything inspectable.

## 7. API (workspace-scoped, UUID-only)

Base: `/api/qynrun`. Reads/dry-run require `accessAi`; approvals, live runs, rollback, deletes require `administerAi`.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/automation/adapters` | Registered execution adapters + `live_execution_enabled`. |
| GET | `/automation/actions` | Action catalog. |
| GET/POST | `/automation/workflows` | List / create workflows. |
| GET/PUT/DELETE | `/automation/workflows/{uuid}` | Read / update / delete. |
| POST | `/automation/workflows/{uuid}/run` | Run (`mode=dry_run|live`). |
| GET/POST | `/automation/runbooks` | List / create runbooks. |
| GET/PUT/DELETE | `/automation/runbooks/{uuid}` | Read / update / delete. |
| POST | `/automation/runbooks/{uuid}/run` | Run. |
| GET/POST | `/automation/executions` | History / ad-hoc dispatch. |
| GET | `/automation/executions/{uuid}` | Execution detail + steps. |
| POST | `/automation/executions/{uuid}/rollback` | Rollback. |
| POST | `/automation/executions/{uuid}/feedback` | Operator feedback (learning). |
| GET | `/automation/approvals` | Pending approvals. |
| POST | `/automation/approvals/{uuid}/decide` | Approve / reject. |
| GET | `/automation/learning` | Aggregated outcomes. |
| GET | `/intelligence/overview` | Automation dashboard. |
| POST | `/intelligence/copilot` | Automation Copilot (reuses Quenyx AI conversations). |
| POST | `/intelligence/runbooks/suggest` | AI-assisted editable runbook draft. |
| POST | `/intelligence/executions/{uuid}/explain` | Explain an execution. |

## 8. Safety summary

Dry-run by default · approval gate for live · HTTP allowlist · per-runner enable flags · rollback · workspace isolation · RBAC · UUID-only · full audit · EN/AR UI. See guide 24 for the platform-level detail.
