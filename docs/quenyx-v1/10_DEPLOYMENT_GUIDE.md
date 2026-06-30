# 10 — Deployment Guide

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 |
> | Classification | Internal |
> | Owner | DevOps / SRE |
> | Status | Released |
> | Last Updated | 2026-06-29 |
> | Document Type | Deployment guide |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial v1 pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | Aligned to v1.0.0; native monitoring scheduler (`observe:run-checks` / `observe:evaluate-alerts`). |

**Audience:** DevOps, implementation partners.
**Canonical source:** This consolidates the repo‑root [`DEPLOYMENT.md`](../../DEPLOYMENT.md) and adds
QCIF/AI specifics. Where the two differ, the root `DEPLOYMENT.md` and the actual `.env.example`
prevail. **Host‑specific values below are examples — substitute your own.**

---

## 1. Prerequisites

| Component | Version |
|---|---|
| PHP | **8.3** recommended (8.1+ supported); extensions: `mbstring, pdo_mysql, openssl, curl, fileinfo, bcmath` |
| Composer | 2.x |
| Node.js / npm | 18+ LTS or 20+ / npm 9+ (`npm ci`) |
| MySQL | 8.0+ |
| Nginx | reverse proxy + static frontend |

## 2. Ubuntu deployment (single node)

Single host runs: Laravel backend (PHP‑FPM or `artisan serve`), Node gateway, Nginx (serves
`frontend/dist`, proxies `/api/`), MySQL, queue worker, and the Laravel scheduler.

```bash
git clone <repo-url> && cd quenyx-saas
mysql -u root -p < scripts/mysql-quenyx-setup.sql   # first-time DB setup
```

## 3. Laravel backend

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
# Edit DB_*, APP_URL, APP_KEY, SEED_ADMIN_PASSWORD, GATEWAY_INTERNAL_SECRET, AI/RAG vars (see §11)
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## 4. React frontend

```bash
cd ../frontend
npm ci
# set VITE_API_BASE_URL to your API base (same-origin /api recommended)
npm run build      # output: frontend/dist
```

## 5. Node gateway

```bash
cd ../gateway
npm ci
npm run build      # rebuild + restart after every change
# env: GATEWAY_PORT=4000, BACKEND_BASE_URL=http://127.0.0.1:8000, ENTITLEMENTS_CACHE_TTL_MS=30000,
#      GATEWAY_INTERNAL_SECRET=<strong shared secret, also set in backend>
```

## 6. Nginx + SSL

- Document root → `frontend/dist`; SPA fallback `try_files $uri /index.html`.
- `location /api/` → `proxy_pass http://127.0.0.1:4000` (gateway), forwarding `Authorization`.
- **SSL:** terminate TLS at Nginx (or LB); redirect HTTP→HTTPS. (See root `DEPLOYMENT.md` for full
  server blocks, single‑node and multi‑node.)

## 7. Environment variables (key ones)

| Var | Purpose |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL` | Core (debug **must** be false in prod) |
| `DB_DATABASE/USERNAME/PASSWORD` | MySQL |
| `QUEUE_CONNECTION=database` | Queue worker |
| `SANCTUM_STATEFUL_DOMAINS` | CORS/auth domains |
| `SEED_ADMIN_PASSWORD` | Required before seeding `UserSeeder` |
| `GATEWAY_INTERNAL_SECRET` | Shared backend↔gateway secret |
| `GATEWAY_BASE_URL` | Public gateway URL for agent enrollment |

## 8. Queues

Port scans and **RAG indexing jobs** require a worker:

```ini
# /etc/systemd/system/quenyx-queue.service  (ExecStart)
/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

## 9. Scheduler

QynSight checks require cron running the Laravel scheduler:

```
* * * * * cd /path/backend && php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

Without it, monitoring shows "Last poll: never".

## 10. Cache

`config:cache`, `route:cache`, `view:cache` in production. Use Redis for cache/sessions in multi‑node
(optional). Run `config:clear` after `.env` changes in dev.

## 11. AI / RAG / Copilot environment (opt‑in)

All AI is **off by default**. Enable deliberately:

| Var | Default | Effect |
|---|---|---|
| `AI_PROVIDER` | `mock` | `openai` to use real models |
| `AI_ENABLED` | `false` | master switch for real model calls |
| `OPENAI_API_KEY` / `OPENAI_MODEL` / `OPENAI_EMBEDDINGS_MODEL` | unset | provider config (models from env only) |
| `COPILOT_ENABLED` | `true` | Copilot on (still mock unless `AI_ENABLED`) |
| `AI_PROMPT_LOGGING_ENABLED` | `false` | store prompt content (keep off) |
| `AI_CONVERSATION_PERSISTENCE_ENABLED` | `false` | persist conversations (keep off) |
| `AI_COPILOT_RAG_ENABLED` / `RAG_ENABLED` / `EMBEDDINGS_ENABLED` | `false` | RAG runtime |
| `VECTOR_PROVIDER` | unset | `openai` (metadata‑only today) |
| `RAG_INDEX_TENANT_EVIDENCE` | `false` | **keep off** (privacy) |

## 12. Storage

Ensure `backend/storage/app/agents` exists and is writable by the web user (agent binaries +
on‑demand Go build). Standard Laravel `storage/` and `bootstrap/cache` permissions apply.

## 13. Database & migrations

```bash
php artisan migrate --force
php artisan migrate:status   # verify all applied
```

## 14. Seeders & corpus

```bash
php artisan db:seed --force                              # core seeders (set SEED_ADMIN_PASSWORD first)
php artisan db:seed --class=ComplianceCorpusSeeder --force
php artisan compliance:seed-source-documents \
  database/corpus/nca/ecc-2-2024/source-documents.json \
  --framework=nca-ecc --release=2:2024
```

Expected corpus: **5 domains, 108 controls, 108 requirements**, active **Revision v1**.

## 15. Backup & restore

- **DB:** `mysqldump` nightly; store offsite/encrypted. Restore: `mysql < dump.sql`.
- **Storage:** back up `backend/storage/` (uploaded artifacts, agent binaries).
- **Config:** keep `.env` in a secrets manager (never in git).

## 16. Rollback

- **App:** `git checkout <previous-tag>` → rebuild backend/frontend/gateway → restart services.
- **DB:** restore from the pre‑deploy dump. **Do not** rely on `migrate:rollback` for destructive
  changes in production; restore from backup.
- **Corpus:** roll back to the prior active revision (deterministic snapshots; see Doc 18).

## 17. Health checks

- Backend: `GET /api/health`.
- Gateway: `GET /health` → `{"status":"ok","service":"gateway"}` (and `/ready`).
- Monitor ports 8000 (backend) and 4000 (gateway); alert on 502/503 and DB connectivity.

## 18. Production checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY`, HTTPS `APP_URL`.
- [ ] `config:cache` + `route:cache` after deploy.
- [ ] CORS restricted to frontend origin; `SANCTUM_STATEFUL_DOMAINS` set.
- [ ] `GATEWAY_INTERNAL_SECRET` set on both sides.
- [ ] `SEED_ADMIN_PASSWORD` set; seeded credentials rotated.
- [ ] Queue worker + scheduler running.
- [ ] AI/RAG flags reviewed (off unless intended; tenant‑evidence indexing off).
- [ ] Migrations applied; corpus counts verified.
- [ ] Backups scheduled.
