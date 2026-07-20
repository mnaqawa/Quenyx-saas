# Deployment Checklist — Quenyx vOPS HUB v1.0.0

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 1.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 (GA) |
> | Classification | Internal |
> | Owner | SRE / DevOps |
> | Status | Released |
> | Last Updated | 2026-07-21 |
> | Document Type | Release artifact — deployment checklist |

## 1. Pre-deployment

- [ ] Release tag **`v1.0.0`** (or current GA tag) checked out on the production host.
- [ ] **Single-node stack** planned: Nginx + PHP-FPM (127.0.0.1:8000) + gateway (4000) + MySQL + cron + `quenyx-queue` (+ QAG on :9444 if agents enabled).
- [ ] Database snapshot/backup taken (or fresh `scripts/mysql-quenyx-setup.sql` for greenfield).
- [ ] `php artisan migrate:status` reviewed (Sprint 24 baseline current).
- [ ] Maintenance window scheduled / `php artisan down` planned if required.
- [ ] AI provider config reviewed (platform is mock-safe if none configured).
- [ ] (Optional) QynBalance `COST_*` rates confirmed — or intentionally left unset (counts only).

## 2. Backend deploy

- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `.env` from `.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, `GATEWAY_INTERNAL_SECRET`, DB `quenyx_dev` / user `quenyx`, `SEED_ADMIN_PASSWORD` (before seed)
- [ ] `php artisan migrate --force` (idempotent; no destructive changes in Sprint 25)
- [ ] `php artisan config:cache && php artisan route:cache && php artisan view:cache`
- [ ] `php artisan quenyx:config-check --strict` passes
- [ ] PHP-FPM + backend Nginx block on **127.0.0.1:8000** (not `artisan serve`)
- [ ] Queue workers restarted (`php artisan queue:restart`)
- [ ] Scheduler (`schedule:run` cron) confirmed running

## 3. Frontend deploy

- [ ] `npm ci`
- [ ] `npm run build` (tsc + vite — must succeed)
- [ ] Build artifacts published to the web tier / CDN
- [ ] Cache-busting verified (new asset hashes served)

## 3b. Gateway (+ optional QAG)

- [ ] `cd gateway && npm ci && npm run build` → `systemctl restart quenyx-gateway`
- [ ] If agents enabled: `cd agent-gateway && npm ci && npm run build` → `systemctl restart quenyx-agent-gateway`

## 4. Configuration

- [ ] Environment variables reviewed; no secrets in VCS
- [ ] `GATEWAY_INTERNAL_SECRET` matches on backend and gateway; gateway **does not** set `OBSERVE_ENGINE_URL`
- [ ] `GATEWAY_BASE_URL` / `APP_URL` / CORS / Sanctum domains match public HTTPS hostname
- [ ] If `AGENT_REQUIRE_GATEWAY=true`: QAG deployed, TLS on :9444, `AGENT_GATEWAY_*` set
- [ ] Cron: `* * * * * php artisan schedule:run` (QynSight native checks)
- [ ] (Optional) `config/cost.php` rates set via env if monetary estimates are desired
- [ ] Entitlements/plans updated to include `qynva` / `qynbalance` where intended

## 5. Smoke tests (post-deploy)

- [ ] Login + workspace selection works
- [ ] `GET /api/health/ready` → 200 when DB reachable
- [ ] Gateway `GET /health` and `GET /ready` (native observe engine)
- [ ] Sidebar shows all enabled modules (QynSight…QynVA); QynCore/Integrations not shown as modules
- [ ] `GET /api/qynva/operator/capabilities` → module catalog
- [ ] `POST /api/qynva/operator/operate` → editable plan (mock-safe if AI disabled)
- [ ] `GET /api/qynva/executive` and `/analytics` → evidence-based data (honest `available:false` where thin)
- [ ] `GET /api/qynva/health` (admin) → `operational`
- [ ] `GET /api/qynbalance/cost/overview` → counts (+ monetary only if rates set)
- [ ] Sprint 20–24 surfaces unchanged (spot-check QynSight, QynRun, QynReact, QynKnow)

## 6. Observability & security

- [ ] Audit logging confirmed (`audit_logs` receiving new entries, incl. `platform_event_published`)
- [ ] RBAC enforced (non-admin blocked from `/health` and `/events`)
- [ ] Workspace isolation spot-checked (no cross-workspace leakage)
- [ ] Error monitoring/alerting green

## 7. Documentation & sign-off

- [ ] Docs Pack v3.0 published; PDFs regenerated (`scripts/docs/build-pdfs-cdp.ps1`)
- [ ] Release Notes (doc 39) circulated
- [ ] Production Readiness Report (doc 44) reviewed and signed
- [ ] `php artisan up` (maintenance mode lifted)

## 8. Rollback plan

- [ ] Previous tag identified and deployable
- [ ] Confirmed: no destructive migrations to reverse; redeploy prior tag + rebuild caches if needed
