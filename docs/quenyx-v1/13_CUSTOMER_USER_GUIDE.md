# 13 — Customer User Guide

**Audience:** End users.
**Scope:** What you can do in the product today. Modules not yet visible are clearly marked.

---

## 1. Dashboard

After signing in you land on your **dashboard**, scoped to your selected workspace. It summarizes
the operational state surfaced by QynSight for that workspace.

## 2. Workspace selection

Use the **workspace selector** (top of the app) to switch between workspaces you belong to (e.g.
**Production Env**, **Staging Env**). All data — monitoring, compliance, members — is scoped to the
active workspace.

## 3. QynSight flows 🟢

QynSight is the visible, production module:

- **Real‑time Monitoring** — live host/service metrics. *(Requires the scheduler running; otherwise
  shows "Last poll: never".)*
- **Infrastructure Map** — topology of hosts/connections; export as JSON/PNG.
- **Performance Analytics** — performance metrics and thresholds.
- **Capacity Planning** — capacity advisor + export.
- **Alert Management** — alert rules, history, acknowledge events, channels, monitoring profiles.
- **Service Checks** — run checks; service definitions and statuses.
- **Hosts / Targets** — add and manage monitored targets; run port scans.
- **Agent enrollment** — generate an enrollment token and install the agent on a host.

## 4. AI Agent expected flows 🟡

- An **AI Agent** entry point exists in the UI (knowledge‑base agent for QynSight).
- The **Compliance Copilot** (QynShield) answers compliance questions with **citations**. By default
  it runs in **mock mode** (safe, deterministic) unless your operator has enabled real‑model AI.
- AI never invents answers: if it can't cite an official source, it won't answer.

## 5. QynShield future user flows 🔵

QynShield's compliance engine (corpus, evidence, gap, recommendations, executive dashboard,
explainability, Copilot) is available via **API today**, with the **executive/demo** surface as the
current UI. Full self‑service compliance UI is on the roadmap.

## 6. Onboarding workflow

1. Accept your invite and sign in.
2. Select your workspace.
3. (QynSight) Add hosts/targets → install agents → watch monitoring populate.
4. (QynShield, if entitled) Explore the executive dashboard and ask the Copilot a question.

## 7. Navigation

- **Sidebar:** modules you can access (today: **QynSight**). Other modules are **not yet visible**.
- **Workspace selector:** switch tenant context.
- **Getting started:** guided steps from the sidebar.

## 8. Common tasks

| Task | Where |
|---|---|
| See system health | Dashboard / Real‑time Monitoring |
| Add a monitored host | Monitored Targets |
| Investigate an alert | Alert Management → history |
| Acknowledge an alert event | Alert event → acknowledge |
| Export the infra map | Infrastructure Map → export |
| Ask a compliance question | Compliance Copilot (if entitled) |

## 9. Troubleshooting

- **"Last poll: never" / stale data** → the scheduler/cron isn't running (contact your admin).
- **Port scan never completes** → the queue worker isn't running (admin).
- **Can't see a module** → it's hidden by design today, or your workspace isn't entitled.
- **Copilot says it can't answer** → expected when there's no citable source; rephrase or scope to a
  loaded framework (NCA ECC‑2:2024).
- **403 Unauthorized** → you're not a member of that workspace, or lack the module entitlement.

## 10. Not‑yet‑visible modules

QynRun, QynAsset, QynKnow, QynNotify, QynReact, QynVA, QynSupport, QynBalance, QynCore, and
QynIntegrations are **on the roadmap** and intentionally **hidden** in the UI today. Only **QynSight**
(and QynShield where entitled/via executive surface) is available.
