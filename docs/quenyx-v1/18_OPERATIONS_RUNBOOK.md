# 18 — Operations Runbook

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.1 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 |
> | Classification | Internal — Operations |
> | Owner | Operations / SRE |
> | Status | Released |
> | Last Updated | 2026-06-30 |
> | Document Type | Operations runbook |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial v1 pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | Aligned to v1.0.0; native monitoring operations; Unified AI Workspace operations. See also `docs/OBSERVE_RUNBOOK.md`. |
> | 2.1 | 2026-06-30 | Added Operations Intelligence (Sprint 21) operations — shares the Quenyx AI runtime; no new services to operate. |

**Audience:** Ops / SRE.
**Scope:** Run, monitor, and recover a Quenyx vOPS HUB deployment. Commands assume the backend dir
and a Linux host (adjust paths).

---

## 1. Daily checks

- [ ] `curl -s https://<host>/api/health` → healthy; gateway `/health` → `{"status":"ok"}`.
- [ ] Scheduler heartbeat: `tail backend/storage/logs/scheduler.log` shows recent `observe:run-checks`.
- [ ] Queue worker alive: `systemctl status quenyx-queue`.
- [ ] Error log scan: `tail -n 200 backend/storage/logs/laravel.log` for ERROR/exception spikes.
- [ ] Disk and DB connectivity OK.

## 2. Weekly checks

- [ ] Review `audit-logs` for unexpected privileged actions.
- [ ] Verify backups exist and are restorable (spot‑restore to staging).
- [ ] Check SSL certificate expiry.
- [ ] Review failed jobs: `php artisan queue:failed`.
- [ ] Confirm AI flags still match intended posture (see §11/§12 below).

## 3. Backups

- **DB:** `mysqldump -u <user> -p <db> > backup-$(date +%F).sql` (automate nightly, encrypt, offsite).
- **Storage:** archive `backend/storage/` (agent binaries, artifacts).
- **Secrets:** `.env` lives in a secrets manager, not in backups‑in‑the‑clear.

## 4. Restores

- **DB:** `mysql -u <user> -p <db> < backup-YYYY-MM-DD.sql`.
- **Storage:** extract the archive to `backend/storage/`.
- Re‑run `php artisan config:cache` after restore; verify health.

## 5. Logs

- Laravel: `backend/storage/logs/laravel.log`; scheduler: `scheduler.log`.
- Gateway: service logs via `journalctl -u quenyx-gateway`.
- Nginx: access/error logs under `/var/log/nginx/`.

## 6. Queues

- Status: `systemctl status quenyx-queue`; restart: `systemctl restart quenyx-queue`.
- Retry failed: `php artisan queue:retry all`; inspect: `php artisan queue:failed`.
- Required for **port scans** and **RAG indexing jobs**.

## 7. Scheduler

- Cron must run `php artisan schedule:run` **every minute** (`crontab -u www-data -l`).
- Scheduled commands (see `backend/app/Console/Kernel.php`): `observe:run-checks` **every 2 minutes**,
  `observe:evaluate-alerts` every minute, `observe:run-port-scans` every 5 minutes,
  `sanctum:prune-expired` daily.
- If monitoring shows "never": confirm cron, PHP path, and `storage/logs` writability; inspect `scheduler.log`.

## 8. SSL

- Renew certs (e.g. certbot); reload Nginx: `systemctl reload nginx`.
- Verify HTTP→HTTPS redirect and HSTS as configured.

## 9. DB health

- `php artisan migrate:status` → all applied.
- Monitor connections, slow queries, and disk. Alert on connectivity loss.

## 10. Cache

- Clear after config changes: `php artisan config:clear` (dev) / `config:cache` (prod).
- Route/view caches: `php artisan route:cache`, `view:cache`. Clear all: `php artisan optimize:clear`.

## 11. Incident handling

1. Confirm scope via health endpoints + logs.
2. Identify the failing component (backend/gateway/db/queue/scheduler).
3. Mitigate (restart service, fail over, disable a feature flag).
4. Capture logs and audit entries.
5. Post‑incident: file the fix forward (new migration/code), update this runbook.

## 12. Rollback

- **App:** deploy previous tag; rebuild backend/frontend/gateway; restart.
- **DB:** restore from pre‑deploy dump (don't `migrate:rollback` destructive changes in prod).
- **Corpus:** roll back to the previous active revision (deterministic snapshot).

## 13. Emergency: disable AI

```bash
# In backend/.env — disables ALL live AI execution (chat returns 503, not silent mock in production)
AI_ENABLED=false
php artisan config:cache
```

This stops external model calls while keeping deterministic features working. Do **not** set
`AI_PROVIDER=mock` in production — use `AI_ENABLED=false` instead.

## 14. Emergency: disable a module

- Per workspace: revoke via `PUT /api/workspaces/{project}/modules/{moduleKey}/override` (audited).
- QynSight gate: remove the `qynsight` entitlement to disable `observe/*` for a workspace.
- Frontend: a hidden module stays hidden via the existing flag (no action needed).

## 15. Troubleshooting commands

```bash
php artisan about                 # environment summary (needs mbstring)
php artisan route:list            # verify routes/no collisions
php artisan migrate:status        # migration state
php artisan queue:failed          # failed jobs
php artisan optimize:clear        # clear caches
tail -f backend/storage/logs/laravel.log
journalctl -u quenyx-gateway -f
```

## Quenyx AI (Unified AI Workspace — Sprint 20) — operations

> **v1.0.0:** UI label is **Quenyx AI**. Routes unchanged (`/api/ai/*`, `/ai-workspace/*`; `/quenyx-ai/*`
> redirects). The provider list is now catalog‑driven and the dev‑only **mock** provider is hidden
> outside `local`/`testing`; the production default provider is **OpenAI** when configured, otherwise
> an honest "no provider configured" state (never mock).

Deploy (after pulling):

```bash
cd backend
composer install --no-dev --optimize-autoloader   # or: composer dump-autoload -o
php artisan migrate --force                        # adds projects.uuid (backfilled),
                                                   # ai_prompt_templates, ai_provider_settings, ai_workspace_permissions
php artisan route:list | grep ai                   # expect /api/ai/workspace/summary, conversations, etc.
php artisan config:cache
```

- **Enable/disable** the surface: `AI_WORKSPACE_ENABLED` (default `true`). When `false`, all
  `/api/ai/*` workspace endpoints return 404 and the sidebar item leads to an empty surface.
- **Live execution (v1.0.0 GA)**: leave `AI_ENABLED` unset to auto-enable when `OPENAI_API_KEY` is set.
  Set `AI_ENABLED=false` to disable by administrator choice (Overview shows **Disabled by administrator**).
  Mock is never used silently in production. Validate with `php artisan quenyx:config-check --strict`.
  Leave `AI_PROVIDER` unset to auto-select OpenAI when configured, or set `AI_PROVIDER=openai` explicitly.
  The provider **catalog** is informational; only providers with a real adapter (today OpenAI) are executable.
  The **Test connection** action / `POST /api/ai/providers/{uuid}/test` runs a genuine `health()` for
  executable providers and is audited.
- **Cost tracking** shows currency only when `ai.workspace.pricing` is configured; otherwise it is
  token‑only by design (no fabricated amounts).
- **Auditing**: AI conversations, provider/template/permission changes are written to `audit_logs`
  (`action LIKE 'ai%'`); surface them via the Activity/Notifications tabs or query the table directly.
- **Rate limiting**: `throttle:ai-workspace` (default 120/min, `AI_WORKSPACE_RATE_LIMIT`).

## QynSight Operations Intelligence (Sprint 21) — operations

Operations Intelligence is an **additive layer** that reuses the Quenyx AI runtime — there is **no new
daemon, queue, or service to operate**. It ships with the same backend deploy (no new migrations; UUIDs
are derived deterministically at runtime). After pulling:

```bash
cd backend
composer dump-autoload -o
php artisan route:list | grep qynsight/intelligence   # expect overview, copilot, alerts/.../explain, etc.
php artisan config:cache
```

- **Prerequisites**: the workspace must have the **`qynsight`** entitlement and the
  **`can_use_ai`** AI capability; the requesting member needs monitoring RBAC. Live operational data
  still requires the **scheduler** (`observe:run-checks`, `observe:evaluate-alerts`) and the **queue
  worker** (port scans) per §6/§7 — Operations Intelligence reads that data and never fabricates it.
- **AI posture**: governed by the same flags as Quenyx AI. With `AI_ENABLED=false` the Copilot and
  ✨ actions return a **clearly flagged mock narrative** over **real** evidence; the emergency
  "disable AI" steps in §13 also apply here (no external model calls).
- **Endpoints**: flat under `/api/qynsight/intelligence/*`, Sanctum + `throttle:ai-workspace`
  (shares the same limiter/budget as Quenyx AI).
- **Auditing**: alert investigations and other AI actions write to `audit_logs` (`action LIKE 'ai%'`);
  recent investigations also surface on the Operations Intelligence dashboard.
- **Troubleshooting**: a `403 Locked` means the `qynsight` entitlement or `can_use_ai` capability is
  missing; an empty/limited narrative with "insufficient evidence" means monitoring has not yet
  collected enough data (check scheduler/agents), **not** an AI failure.

## QynAsset Asset Intelligence + AI Adapter Platform (Sprint 22)

- **Endpoints**: flat under `/api/qynasset/intelligence/*`, Sanctum + `throttle:ai-workspace` (shares
  the Quenyx AI limiter/budget); adapter discovery under `/api/ai/adapters` + `/api/ai/actions`.
- **Entitlement/RBAC**: a `403` means the `qynasset` entitlement, `accessAi` RBAC, or `can_use_ai`
  capability is missing. UUID‑only — a `404 Asset not found` means the asset UUID does not resolve to a
  host in this workspace.
- **Empty/honest output**: an asset overview with `total: 0` means no hosts/agents are discovered yet
  (enroll agents / define hosts) — not an AI failure. License/lifecycle‑date sections returning
  `available: false` is **expected** (no inventory/license integration configured), **not** an error;
  Quenyx AI never fabricates those facts.
- **Auditing**: asset AI actions write to `audit_logs` (`action LIKE 'asset_intelligence_%'` and
  `ai_adapter_discovery_%`); recent investigations also surface on the Asset Intelligence dashboard.
- **No duplicated AI**: QynAsset reuses the shared `ModuleAiNarrator`/provider runtime; there is no
  separate provider, prompt, reasoning, or RAG engine to operate.

---

## Automation & Incident operations (Sprint 23)

- **Enabling live automation**: live execution is OFF by default. To allow real actions, set
  `AUTOMATION_LIVE_EXECUTION=true`, enable the specific runner flag(s), and populate
  `automation.allowed_hosts` for HTTP actions. Re-run config cache. Verify by dispatching a dry-run
  first, then a live action — confirm it lands in **Approvals** before it runs.
- **Approving / rolling back**: live actions wait in `GET /api/qynrun/automation/approvals`; an
  `administerAi` operator decides. To undo a successful, rollback-capable run, call
  `POST /automation/executions/{uuid}/rollback`.
- **Triage an incident**: open the QynReact workspace (`GET /api/qynreact/incidents/{uuid}`), review
  cross-module context, use Copilot/Recommend, mitigate via QynRun, then draft an editable postmortem.
- **Auditing**: automation events are written via `AutomationAuditLogger` (`action LIKE
  'automation_%'`); incident actions under `incident_*`. Outcomes are captured as auditable learning
  records — no model training, no hidden state.
- **Stuck/long runs**: executions honor `automation.default_timeout` and retry policy; check
  `automation_executions` / `automation_execution_steps` for status and step-level detail.
- **No duplicated automation**: QynRun/QynReact and all future modules consume the shared Automation
  Platform — there is no module-specific execution engine to operate. See Docs 24–27.

---

## Knowledge, Service Desk & Notification operations (Sprint 24)

- **Check knowledge sources**: `GET /api/qynknow/sources` lists operational vs planned providers. The
  Internal KB is the only operational source until external providers are wired; planned sources report
  non-operational and are skipped by Enterprise Search (no fabricated results).
- **Enterprise Search / Timeline / Graph** are **read-models** over real rows — they write nothing and
  cannot affect other modules. If results look stale, confirm the underlying rows exist; there is no
  separate index to rebuild for the Internal KB (it queries `knowledge_documents` directly).
- **Notification triage**: signals are ingested via `POST /api/qynnotify/notifications` with deterministic
  deduplication (`dedup_key`), correlation (`correlation_id`), and urgency scoring. Recipients are real
  members only; if critical signals select no privileged member, the workspace owner is the fallback.
  Tune `config/knowledge.php → notifications` (severity weights, correlation window) and re-cache config.
- **Service desk triage**: `POST /api/qynsupport/tickets/{uuid}/intelligence/analyze` returns
  evidence-based suggestions; when there is no history it reports **"insufficient evidence"** rather than
  guessing an assignee.
- **Auditing**: all Sprint 24 events are written via `PlatformAuditLogger` to the shared `audit_logs`
  table. All data is workspace-isolated and UUID-only.
- **No duplicated AI/orchestration**: knowledge, ticket, and notification intelligence all narrate
  through `ModuleAiNarrator` and reuse the `CrossModuleOrchestrator`. See Docs 28–32.

---

## Enterprise Intelligence operations (Sprint 25)

- **Platform Health is the first stop**: `GET /api/qynva/health` (requires `administerAi`) reports overall
  + per-area status (AI/automation/knowledge platforms, search, registries, providers, queues, event bus,
  background jobs). For any `degraded`/`down` area, read its `reason` and follow the matching registry/
  queue/provider check. UI: `/qynva/health`.
- **Event Bus introspection**: `GET /api/qynva/events` (privileged) shows the event vocabulary, registered
  subscribers (and what each listens to), and the recent event ring. A subscriber that throws is caught and
  logged — it **never** breaks publishing. Every publish is audited as `platform_event_published`.
- **QynVA never executes**: the operator (`POST /api/qynva/operator/operate`) only returns editable,
  evidence-based plans referencing existing module actions; humans approve and the owning module executes.
- **QynBalance honesty**: `GET /api/qynbalance/cost/overview` shows real counts and monetary figures **only
  where `config/cost.php` rates are set**; otherwise it reports "pricing unavailable" with a
  `configure_pricing` recommendation. This is expected behavior, not a fault. Set `COST_*` env to enable
  monetary estimates and re-cache config.
- **AI disabled**: Executive summaries, QynVA, and the cost copilot fall back to the deterministic mock
  provider (flagged `ai_enabled:false`); the underlying evidence is always real.
- **Async migration**: Event Bus fan-out is synchronous-but-isolated; replacing the `dispatch()` body with
  a queued job requires no changes to publishers or subscribers. See Docs 33–36, 44.
