# 18 — Operations Runbook

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

- Cron must run `php artisan schedule:run` every minute (`crontab -u www-data -l`).
- If monitoring shows "never": confirm cron, PHP path, and `storage/logs` writability.

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
# In backend/.env
AI_ENABLED=false
AI_PROVIDER=mock
RAG_ENABLED=false
AI_COPILOT_RAG_ENABLED=false
php artisan config:cache   # or config:clear in dev
```

This forces mock mode — **no external model calls** — while keeping deterministic features working.

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
