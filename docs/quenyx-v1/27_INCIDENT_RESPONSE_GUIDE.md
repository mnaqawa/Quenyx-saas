# 27 — Incident Response Guide

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 1.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 |
> | Classification | Confidential — Product |
> | Owner | SRE / Operations |
> | Status | Released |
> | Last Updated | 2026-06-30 |
> | Document Type | Operational playbook |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026-06-30 | Initial incident response playbook (Sprint 23) using QynReact + the shared Automation Platform. |

**Audience:** On-call engineers and incident commanders.
**Scope:** The end-to-end incident lifecycle using QynReact (guide 26), QynRun automation (guide 25), and the shared Automation Platform (guide 24).

---

## 1. Lifecycle

```
Detect (alert / manual)
   ↓ open incident (QynReact)
Triage  → review cross-module context (QynSight + QynAsset)
   ↓
Investigate → Incident Copilot (grounded Q&A) + Recommend
   ↓
Mitigate → run a runbook / action via QynRun (dry-run → approve → live)
   ↓
Resolve → set resolution, status = resolved
   ↓
Learn → AI-drafted postmortem (editable) + automation learning updated
```

## 2. Step-by-step

1. **Open** an incident (`POST /api/qynreact/incidents`) with a title and severity. Link an `alert_uuid` / `asset_uuid` when known (deterministic UUIDv5 from QynSight/QynAsset).
2. **Open the workspace** (`GET /incidents/{uuid}`). Review the **Cross-module** section — Operations & Asset Intelligence are reused, never re-collected.
3. **Investigate** with the **Incident Copilot** and **Recommend**. Recommendations cite cross-module evidence and historical automation success rates.
4. **Mitigate** in QynRun:
   - Start with a **dry-run** to preview the plan.
   - For a real fix, dispatch **live** → the action enters **Approvals** → an authorized operator approves → it runs.
   - Use **Rollback** if a successful live action must be undone.
5. **Document** the timeline (`POST /incidents/{uuid}/timeline`) and set the **resolution**.
6. **Postmortem** — generate an editable draft (`POST /incidents/{uuid}/postmortem`), review the root-cause **hypothesis**, finalize action items, and save.

## 3. Safety rules (non-negotiable)

- No automatic destructive actions — **every live action requires approval**.
- Dry-run is always available and is the default.
- AI never fabricates: missing data (e.g. Knowledge until QynKnow is connected) is reported honestly.
- Everything is workspace-scoped, UUID-only, RBAC-gated, and fully audited.

## 4. Roles

| Action | Minimum role |
|---|---|
| View incidents / dry-run / copilot | `accessAi` + `can_use_ai` (for AI) |
| Approve live actions / rollback | `administerAi` |

## 5. After-action

Automation outcomes are captured as auditable learning records; future recommendations cite them. Review the QynRun **Learning** tab periodically to spot low-success actions and refine runbooks.
