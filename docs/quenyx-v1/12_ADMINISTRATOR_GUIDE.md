# 12 — Administrator Guide

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.0 |
> | Software Version | v1.0.0 RC1 |
> | Applies To | Quenyx vOPS HUB v1.0.0 RC1 |
> | Classification | Internal |
> | Owner | Operations |
> | Status | Released |
> | Last Updated | 2026-06-29 |
> | Document Type | Administrator guide |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial v1 pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | Aligned to v1.0.0 RC1; native monitoring administration; Unified AI Workspace administration. |

**Audience:** Platform administrators.
**Scope:** Admin tasks supported by the current product at v1.0.0 RC1 (including the Unified AI
Workspace, Sprint 20).

---

## 1. Login

- Sign in at the SPA with your credentials (`POST /api/auth/login`). The seeded admin is
  `admin@quenyx.test` with the password set via `SEED_ADMIN_PASSWORD` at deploy time. **Rotate it
  after first login.**
- Manage your own profile/password under `auth/me`.

## 2. Workspace management

- A **workspace** = a **project** (the tenant boundary). Create/list/update/delete via the Projects
  UI or `/api/workspaces`.
- The seeder provisions **Production Env** and **Staging Env** by default (adjust in `ProjectSeeder`).

## 3. Project management

- Each workspace has its own monitoring data, compliance scope, members, subscription, audit log,
  and integrations. Switch the active workspace from the workspace selector.

## 4. Roles

- Members are attached to a workspace via **memberships** with a **role**. Manage via the
  Memberships UI or `/api/workspaces/{project}/memberships` (invite, update role, remove).
- Authorization is enforced by `ProjectPolicy` — only members can access a workspace's data.

## 5. Users

- Users register/are invited, then accept an invite token (`POST /api/invites/{token}/accept`) to
  join a workspace.

## 6. Module subscriptions

- View entitlements: `GET /api/workspaces/{project}/entitlements` and `…/modules/access`.
- Manage subscription: `…/subscription` (GET/PUT).
- **Per‑module override:** `PUT /api/workspaces/{project}/modules/{moduleKey}/override` — grants/
  revokes a module for a workspace. Overrides are **audited**.
- Today the **sidebar shows QynSight only** (other modules are hidden by a frontend flag); module
  entitlement data still exists for all modules.

## 7. QynSight administration

- Configure **monitored targets/hosts** (`observe/targets`), **service definitions**, and
  **alert rules** (create/update/toggle/delete) and **monitoring profiles**.
- Manage **agents**: create enrollment tokens, list/revoke tokens, view metadata, remove agents.
- Ensure the **scheduler** (cron) and **queue worker** run (see Doc 10) — otherwise checks/port
  scans won't run.

## 8. QynShield administration

- Requires the **QynShield entitlement** (`project.qynshield`) on the workspace.
- Corpus is seeded by operators (NCA ECC‑2:2024 v1). Admins consume corpus/graph/mapping/evidence/
  gap/recommendation/executive APIs; there is no in‑UI corpus authoring (official‑source‑only model).

## 9. AI settings

- AI is configured via **environment variables**, not a UI toggle today (see Doc 10 §11).
- Defaults are safe: mock provider, AI off, no prompt logging, no conversation persistence, RAG off,
  no tenant‑evidence indexing. Change only deliberately.

## 10. Audit logs

- `GET /api/workspaces/{project}/audit-logs` — review sensitive actions (module overrides, executive
  reads, copilot, etc.). Use for access reviews and incident investigation.

## 11. Integrations

- Workspace integrations and billing integrations via `…/integrations` and `…/billing/integrations`.
  Configure per‑workspace settings; secrets are stored as configuration, not in code.

## 12. Limitations

- No self‑service AI toggle UI yet (env‑driven).
- QynShield UI beyond executive/demo is roadmap.
- Hidden modules cannot be enabled in the sidebar without changing the frontend flag (out of scope
  pre‑Sprint 20).

## Unified AI Workspace administration (Sprint 20)

Open **AI Workspace** from the top‑level sidebar (beside Integrations), then select a workspace. Tabs:
Overview, Chat, Conversations, History, Activity, Memory, Prompt Templates, Skills, Capabilities,
Usage, Costs, Providers, Permissions, Administration, Notifications.

- **Access**: any workspace member can open the AI Workspace; **owner/admin** can administer.
- **Providers** (Administration → Providers): set the per‑workspace model and (optionally) an
  encrypted API key. Secrets are write‑only — they are stored encrypted and never shown again; the UI
  only indicates whether a secret is configured. AI execution still requires the platform AI flags.
- **Permissions** (Administration → Permissions): per‑role matrix (`Use AI`, `Manage templates`,
  `Manage providers`, `View costs`, `Administer`). Rows are additive overrides on top of role
  defaults; the `owner` row is always full and locked.
- **Cost tracking**: amounts appear only when pricing is configured in `config/ai.php`
  (`ai.workspace.pricing`); otherwise token usage is shown without monetary values.
- **Audit**: provider/template/permission changes and conversations are recorded in the audit log and
  surfaced under Activity / Notifications.
- **Disable**: set `AI_WORKSPACE_ENABLED=false` to hide the surface (returns 404 from the API).
