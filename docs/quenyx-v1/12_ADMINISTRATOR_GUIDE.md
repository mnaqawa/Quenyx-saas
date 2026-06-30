# 12 — Administrator Guide

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.1 |
> | Software Version | v1.0.0 RC1 |
> | Applies To | Quenyx vOPS HUB v1.0.0 RC1 |
> | Classification | Internal |
> | Owner | Operations |
> | Status | Released |
> | Last Updated | 2026-06-30 |
> | Document Type | Administrator guide |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial v1 pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | Aligned to v1.0.0 RC1; native monitoring administration; Unified AI Workspace administration. |
> | 2.1 | 2026-06-30 | Added administration of QynSight Operations Intelligence (Sprint 21): entitlement + `can_use_ai` capability, audit, and rate limits. |

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

## Quenyx AI administration (Unified AI Workspace — Sprint 20)

> **RC1.1:** this surface is now branded **Quenyx AI** in the UI (sidebar, header, breadcrumbs). The
> canonical SPA route stays `/ai-workspace/*` for backward compatibility; the branded `/quenyx-ai/*`
> path redirects to it. Do not confuse Quenyx AI (the AI control center) with **Workspaces**
> (tenant/project management).

Open **Quenyx AI** from the top‑level sidebar (beside Integrations), then select a workspace. Tabs are
grouped into **Workspace** (Overview, Chat, Conversations, History, Activity), **Intelligence**
(Skills, Capabilities, Memory, Prompt Templates), **Operations** (Usage, Costs, Providers), and
**Administration** (Permissions, Administration, Notifications).

- **Access**: any workspace member can open Quenyx AI; **owner/admin** can administer.
- **Providers** (Operations → Providers): an enterprise provider catalog rendered as cards. Each card
  shows the provider label, type (hosted / gateway / self‑hosted / custom), declared capabilities,
  endpoint, default indicator, whether a live execution adapter exists (`executable`), whether
  platform credentials are present, the per‑workspace enabled/disabled and configured/secret state,
  and last‑updated. Actions: **Configure/Edit**, **Enable/Disable**, **Test connection** (a real
  readiness probe — executable providers run a health check, others report "catalog only"), and
  **Clear secret**. Set the per‑workspace model and (optionally) an encrypted API key. Secrets are
  write‑only — stored encrypted and never shown again; the UI only indicates whether a secret is
  configured. The **default provider** is set by platform configuration (`AI_PROVIDER`, or OpenAI
  credentials), not per‑workspace. The catalog lists OpenAI, Anthropic, Gemini, Azure OpenAI,
  OpenRouter, Mistral, Cohere, xAI Grok, Ollama, LM Studio, vLLM, LiteLLM, Hugging Face, and a Custom
  OpenAI‑compatible API; today **only OpenAI is executable** — others are configurable but not yet
  executable, and the **mock** provider is hidden outside local/testing. AI execution still requires
  the platform AI flags.
- **Permissions** (Administration → Permissions): per‑role matrix (`Use AI`, `Manage templates`,
  `Manage providers`, `View costs`, `Administer`). Rows are additive overrides on top of role
  defaults; the `owner` row is always full and locked.
- **Cost tracking**: amounts appear only when pricing is configured in `config/ai.php`
  (`ai.workspace.pricing`); otherwise token usage is shown without monetary values.
- **Audit**: provider/template/permission changes and conversations are recorded in the audit log and
  surfaced under Activity / Notifications.
- **Disable**: set `AI_WORKSPACE_ENABLED=false` to hide the surface (returns 404 from the API).

## QynSight Operations Intelligence administration (Sprint 21)

Operations Intelligence adds an explainable AI layer to QynSight (Monitoring Copilot, Alert/Root‑Cause/
Capacity/Performance/Infrastructure/Service‑Health intelligence, evidence‑based recommendations, and an
Operations Intelligence dashboard). It **reuses the Quenyx AI runtime** — there is nothing new to
provision, no new provider to configure, and no new secret to manage.

- **Entitlement & capability**: a workspace must have the **`qynsight`** module entitlement *and* the
  **`can_use_ai`** AI capability (manage it in Quenyx AI → Administration → Permissions per role). A
  member also needs monitoring RBAC. Without these, the endpoints return `403 Locked`.
- **AI posture**: governed by the **same** AI flags as Quenyx AI (§9 and Doc 10 §11). With AI disabled,
  the Copilot/✨ actions return a **clearly flagged mock narrative** over **real** monitoring evidence —
  no external model calls and no fabricated operational data.
- **Where admins see it**: the Operations Intelligence dashboard lives under QynSight
  (`/observe/operations-intelligence`); contextual **✨ Quenyx AI** actions appear on the Hosts,
  Services, Alerts, Capacity Planning, and Infrastructure Map pages.
- **Audit & limits**: every AI action is audited (`audit_logs`, `action LIKE 'ai%'`), provider‑logged,
  conversation‑logged, and rate limited via `throttle:ai-workspace`. Copilot threads are real Quenyx AI
  conversations and appear in the AI Activity/History surfaces.
- **Data prerequisites**: insights are only as rich as the monitoring data — ensure the scheduler and
  agents are healthy so hosts/services/alerts/metrics/capacity are current.

### QynAsset Asset Intelligence + AI Adapter Platform (Sprint 22)

- **Entitlement**: Asset Intelligence requires the `qynasset` module entitlement for the workspace, on
  top of the same RBAC (`accessAi`) and `can_use_ai` capability used by all AI actions.
- **Where**: the **Asset Intelligence** dashboard lives at
  `/app/workspaces/:id/qynasset/intelligence`; contextual **✨** actions (Explain / Analyze / Forecast
  / Impact / Review) open the Asset Copilot, which reuses Quenyx AI conversations.
- **Data prerequisites**: an asset is a **discovered host** — enroll agents and define monitored hosts
  so inventory, agent status, and hardware facts are present. Software‑license and warranty/EOL data
  are **not collected** until an inventory/license integration is connected; until then those
  capabilities honestly report "not collected" rather than fabricating values.
- **Adapter discovery**: administrators/integrators can inspect which AI modules a workspace can use
  via `GET /api/ai/adapters` (entitlement‑filtered). Every AI action remains audited, provider‑logged,
  conversation‑logged, and rate‑limited.

---

## Automation Platform administration (Sprint 23)

QynRun/QynReact run on the shared Automation Platform. Administration is **safe by default**:

- **Live execution master switch.** `automation.live_execution` (env `AUTOMATION_LIVE_EXECUTION`)
  defaults to **OFF**. While OFF, every action is dry-run only — no side effects are possible.
- **Per-runner enable flags.** Script, SSH, and PowerShell runners each have their own enable flag in
  `config/automation.php`; until enabled they honestly report `skipped` for live requests.
- **HTTP allowlist.** REST/Webhook actions only reach hosts in `automation.allowed_hosts`.
- **Approval gate.** No destructive/live action runs without human approval. Approving, rejecting,
  rolling back, and deleting require `administerAi`; viewing and dry-run require `accessAi`.
- **Rollback.** Successful, rollback-capable executions can be undone from the Executions view.
- **Auditing & learning.** All automation events are written via `AutomationAuditLogger`; outcomes are
  captured as auditable learning records (no model training, no hidden state) and inform — but never
  auto-trigger — AI recommendations.

All automation and incident data is workspace-isolated and UUID-only. See the **Automation Platform
Guide (Doc 24)** and **Incident Response Guide (Doc 27)**.

---

## Knowledge & Collaboration Platform administration (Sprint 24)

QynKnow/QynSupport/QynNotify run on shared platform services. Administration notes:

- **Knowledge sources.** The Internal Knowledge Base is operational out of the box. All external
  providers (Markdown/PDF/HTML/Git/Confluence/SharePoint/Drive/OneDrive/Wikis/Elastic·OpenSearch/Vector)
  are **registered but planned** and report as non-operational until wired — Enterprise Search skips them
  cleanly. The live registry is visible at `GET /api/qynknow/sources`.
- **AI surfaces.** Knowledge Assistant, Ticket Intelligence, and Notification Intelligence respect the
  same AI flags as the rest of the platform (`AI_WORKSPACE_ENABLED`, provider config) and narrate only
  through `ModuleAiNarrator`. They are evidence-based, editable, and never auto-apply.
- **Notification routing.** Recipients are only ever **real workspace members**; channel selection is
  recorded intent until a transport is connected (no fake dispatch). Tune severity weights and the
  correlation window in `config/knowledge.php → notifications`.
- **Collaboration.** A shared, polymorphic capability available to any workspace member (`accessAi`);
  there is no per-module collaboration system to administer separately.
- **RBAC / isolation / audit.** All Sprint 24 data is workspace-isolated, UUID-only, and audited via
  `PlatformAuditLogger`. AI surfaces require `can_use_ai`. See Docs 28–32.

---

## Enterprise Intelligence administration (Sprint 25)

- **Navigation.** The temporary sidebar feature flag is removed; all business modules (QynSight, QynAsset,
  QynRun, QynReact, QynKnow, QynSupport, QynNotify, QynShield, QynBalance, QynVA) are enabled. QynCore and
  Integrations remain platform-only. Per-workspace exposure is still controlled by **plan entitlements**
  (`modules_allowed`) and AI RBAC.
- **QynVA & QynBalance entitlements.** Add `qynva` / `qynbalance` to a plan's `modules_allowed` to expose
  them. Both are `ai_candidate` and respect the same AI flags/provider config as every module.
- **QynBalance pricing.** No financial values are fabricated. To enable monetary estimates, set real rates
  in `config/cost.php` via `COST_*` env (host/agent/service/seat/automation-minute, optional budget) and
  re-cache config. Until then QynBalance shows real counts + "pricing unavailable".
- **Privileged surfaces.** Platform Health (`/api/qynva/health`) and Event Bus introspection
  (`/api/qynva/events`) require `administerAi`. Use Platform Health to confirm AI/automation/knowledge
  platforms, registries, providers, queues, and the event bus are operational.
- **RBAC / isolation / audit.** All Sprint 25 surfaces are workspace-isolated, UUID-only, and audited
  (incl. `platform_event_published`). QynVA proposes editable plans and never executes. See Docs 33–44.
