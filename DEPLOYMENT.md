# Quenyx vOPS HUB – Deployment

**PROPRIETARY SOFTWARE - Copyright (c) 2026 Quenyx CO. All rights reserved.**

This document describes how to deploy the Quenyx vOPS HUB monorepo (backend, frontend, gateway) for development and production.

## Prerequisites

| Component   | Version / Notes |
|------------|------------------|
| PHP        | 8.1+             |
| Composer   | 2.x              |
| Node.js    | 18+ LTS or 20+   |
| npm        | 9+ (use `npm ci` for reproducible installs) |
| MySQL      | 8.0+             |
| Nginx      | For production (reverse proxy, static frontend, `/api/` to gateway) |

## Single-Node Deployment (Development / Staging)

Single server runs: Laravel backend, Node gateway, Nginx serving frontend static build and proxying `/api/` to the gateway.

### 1. Clone and enter repo

```bash
git clone <repository-url>
cd quenyx-saas
```

### 1b. MySQL (first-time or Quenyx rename)

Fresh install:

```bash
mysql -u root -p < scripts/mysql-quenyx-setup.sql
```

Existing databases with pre-rebrand names: see `docs/QUENYX_DEPLOYMENT_AND_CHANGES.md` §4.2 and `scripts/migrate-mysql-to-quenyx-databases.sh`.

### 2. Backend

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
# Edit .env: DB_DATABASE=quenyx_dev, DB_USERNAME=quenyx, DB_PASSWORD=..., APP_URL, etc.
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Note:** `view:cache` requires `backend/resources/views/` to exist (a placeholder `welcome.blade.php` is included). If deploy fails with *directory does not exist*, pull latest backend or create the directory before caching.

**QynSight / observe routing:** Do **not** set `OBSERVE_ENGINE_URL` on the gateway. All `/api/*` traffic (including observe) must go to `BACKEND_BASE_URL` only. Legacy split-engine routing caused empty hosts and 60s hangs.

Set `SEED_ADMIN_PASSWORD` in backend `.env` before running seeds.
Seed admin login will be: `admin@quenyx.test` / `<SEED_ADMIN_PASSWORD>`.

### 3. Frontend (reproducible build)

```bash
cd ../frontend
npm ci
npm run build
```

Static output: `frontend/dist/`. Nginx will serve this as the document root.

### 4. Gateway

```bash
cd ../gateway
npm ci
npm run build
```

**Important:** After any gateway code change, run `npm run build` and restart the gateway service so `dist/` is updated.

**Environment (systemd or .env):**

```bash
GATEWAY_PORT=4000
BACKEND_BASE_URL=http://127.0.0.1:8000
ENTITLEMENTS_CACHE_TTL_MS=30000
```

**Backend `.env` – Agent install instructions:**

Set `GATEWAY_BASE_URL` in `backend/.env` to your **public** gateway URL (e.g. `https://quenyx.example.com`). This is used for agent download and enrollment commands. If unset, it falls back to `APP_URL` or `http://127.0.0.1:4000`.

```bash
GATEWAY_BASE_URL=https://your-public-domain.com
```

After changing `.env`, run `php artisan config:clear` (or `php artisan config:cache` in production).

**Agent binaries (for Install Agent download):**

The route `GET /api/agents/download/{platform}` serves the agent binary. When a binary is missing, the server can build it on demand (requires Go). Configure in `backend/.env`:

- **AGENT_GO_BINARY** – Path to the Go binary. PHP-FPM often has a minimal PATH, so set this to the full path (e.g. `AGENT_GO_BINARY=/usr/bin/go`). If unset, `go` is used and may not be found when the web server runs.
- **AGENT_SOURCE_PATH** – Directory containing the agent Go module (`go.mod`). Default is the `agent/` directory next to the backend (e.g. repo root `agent/`). Set if your deploy layout differs.
- **AGENT_BUILD_ON_DEMAND** – Set to `false` to disable on-demand build and only serve pre-built binaries from `storage/app/agents/`.

Ensure **backend/storage/app/agents** exists and is writable by the web server user (e.g. `www-data`), so on-demand build can write binaries, Go cache dirs (`.gocache`, `.gomodcache`), and the download endpoint can serve them. Example: `sudo mkdir -p backend/storage/app/agents && sudo chown www-data:www-data backend/storage/app/agents && sudo chmod 775 backend/storage/app/agents`.

Built or pre-placed files in `backend/storage/app/agents/`: `linux-amd64`, `linux-arm64`, `windows-amd64`, `windows-arm64`, `darwin-amd64`, `darwin-arm64`. See `agent/README.md` for manual build commands. If the server cannot build or find a binary, the endpoint returns JSON with a `message` explaining the reason (e.g. "Go binary not found").

**Optional – QynSight / Nagios:**

```bash
NAGIOS_BIN=nagios
NAGIOS_CONFIG_DIR=./nagios/config
NAGIOS_CONTAINER_NAME=nagios-core
```

See [gateway/README.md](gateway/README.md) for Nagios binary resolution and `GET /ready` checks.

### 5. Nginx (single node)

Example: site root = frontend build; `/api/` proxied to gateway.

```nginx
server {
    listen 80;
    server_name dev.quenyx.net;
    root /var/www/quenyx/quenyx-saas/frontend/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:4000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Authorization $http_authorization;
        proxy_cache_bypass $http_upgrade;

        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }
}
```

### 6. Systemd services

**Backend (production: PHP-FPM, NOT `php artisan serve`)**

> ⚠️ **GA requirement:** `php artisan serve` is a single-threaded development
> server and must **not** be used in production. Serve the Laravel backend with
> **PHP-FPM** behind a dedicated Nginx server block. The Node gateway proxies to
> this backend (`BACKEND_BASE_URL=http://127.0.0.1:8000`).

1. Install PHP-FPM (e.g. `php8.2-fpm`) and confirm the pool socket
   (`/run/php/php8.2-fpm.sock`). PHP-FPM is managed by its own systemd unit
   (`php8.2-fpm.service`) — no custom backend unit is needed.

2. Add a backend Nginx server block listening on `127.0.0.1:8000` with the
   document root at `backend/public`:

   ```nginx
   server {
       listen 127.0.0.1:8000;
       server_name _;
       root /var/www/quenyx/quenyx-saas/backend/public;
       index index.php;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       location ~ \.php$ {
           include snippets/fastcgi-php.conf;
           fastcgi_pass unix:/run/php/php8.2-fpm.sock;
       }

       location ~ /\.(?!well-known).* { deny all; }
       client_max_body_size 25m;
   }
   ```

3. Validate config and warm caches after deploy:

   ```bash
   cd backend
   php artisan quenyx:config-check   # fails fast on production misconfig
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   sudo systemctl reload php8.2-fpm nginx
   ```

> For containerized deployments, run `php-fpm` as the container command instead
> of `artisan serve`, with Nginx (sidecar or ingress) in front.

**Gateway**

`/etc/systemd/system/quenyx-gateway.service`:

```ini
[Unit]
Description=Quenyx API Gateway
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/quenyx/quenyx-saas/gateway
Environment="GATEWAY_PORT=4000"
Environment="BACKEND_BASE_URL=http://127.0.0.1:8000"
Environment="ENTITLEMENTS_CACHE_TTL_MS=30000"
ExecStart=/usr/bin/node dist/server.js
Restart=always

[Install]
WantedBy=multi-user.target
```

### 7. Laravel scheduler (cron) – required for QynSight

The scheduler runs `observe:run-checks` every minute for host/service monitoring. **Without this, Real-time Monitoring and Infrastructure Map will show stale or "never" data.**

Add to the **www-data** user crontab (or the user that runs the backend):

```bash
sudo crontab -u www-data -e
```

Add this line (adjust the path if your backend lives elsewhere):

```
* * * * * cd /var/www/quenyx/quenyx-saas/backend && php artisan schedule:run >> /var/www/quenyx/quenyx-saas/backend/storage/logs/scheduler.log 2>&1
```

**Verify:**
- Wait 1–2 minutes, then check `backend/storage/logs/scheduler.log` – it should contain output from `observe:run-checks` (e.g. "Ran X native check(s).").
- If the file stays empty, confirm: (1) crontab is installed for the correct user, (2) PHP path is correct (`which php`), (3) `storage/logs/` is writable by the cron user.

**After adding or changing `config/queue.php`**, run:
```bash
cd backend && php artisan config:clear && php artisan config:cache
```

### 8. Queue worker (for port scans and other background jobs)

Port scans (especially full 1–65535) run as background jobs. **Without a queue worker, scans will not complete.**

1. **Create jobs table** (if not exists):
   ```bash
   cd backend && php artisan migrate --force
   ```

2. **Set queue driver** in `.env`:
   ```
   QUEUE_CONNECTION=database
   ```

3. **Add queue worker systemd service** `/etc/systemd/system/quenyx-queue.service`:
   ```ini
   [Unit]
   Description=Quenyx Queue Worker
   After=network.target

   [Service]
   Type=simple
   User=www-data
   WorkingDirectory=/var/www/quenyx/quenyx-saas/backend
   ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
   Restart=always

   [Install]
   WantedBy=multi-user.target
   ```

4. **Enable and start**:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable quenyx-queue
   sudo systemctl start quenyx-queue
   ```

**Verify:** Trigger a port scan from the UI; check `storage/logs/laravel.log` or the Port Scan tab for results.

### 9. Enable and start

```bash
sudo systemctl daemon-reload
# Backend is served by PHP-FPM (php8.2-fpm), not a custom quenyx-backend unit.
sudo systemctl enable php8.2-fpm quenyx-gateway quenyx-queue
sudo systemctl start php8.2-fpm quenyx-gateway quenyx-queue
sudo systemctl reload nginx
```

### 10. Health checks

- Gateway: `curl http://127.0.0.1:4000/health` → `{"status":"ok","service":"gateway"}`
- Backend **liveness**: `curl http://127.0.0.1:8000/api/health` → `200` lightweight liveness response.
- Backend **readiness**: `curl http://127.0.0.1:8000/api/health/ready` → `200` when DB/cache are reachable, `503` when not. Use this for load-balancer / orchestrator readiness probes (do **not** route traffic until it returns `200`).

### 11. Backups, restore verification & disaster recovery

Automated, verified logical backups are provided under `scripts/`:

```bash
# Create a compressed, checksummed, self-verifying backup (safe for cron).
scripts/backup-db.sh /var/backups/quenyx

# Prove a backup is restorable WITHOUT touching the live DB (temp DB, auto-dropped).
scripts/restore-db.sh /var/backups/quenyx/quenyx-<db>-<timestamp>.sql.gz

# Real restore into a target database (interactive confirmation required).
scripts/restore-db.sh <backup_file.sql.gz> --target quenyx_dev
```

Schedule nightly backups + a weekly restore-verification via cron:

```cron
# Nightly backup at 02:30 (retains BACKUP_RETENTION_DAYS, default 14).
30 2 * * *  /var/www/quenyx/quenyx-saas/scripts/backup-db.sh /var/backups/quenyx >> /var/log/quenyx-backup.log 2>&1
# Weekly restore verification (Sundays 03:30) against the most recent backup.
30 3 * * 0  f=$(ls -t /var/backups/quenyx/quenyx-*.sql.gz | head -n1); /var/www/quenyx/quenyx-saas/scripts/restore-db.sh "$f" >> /var/log/quenyx-restore-verify.log 2>&1
```

> **DR note:** Store backups off-host (object storage / different volume). The
> backup script writes a `.sha256` sidecar; `restore-db.sh` verifies gzip
> integrity and checksum before any restore, so a corrupt backup fails loudly
> instead of silently restoring bad data.

---

## Multi-Node Deployment (Production)

- **Load balancer**: Nginx (SSL termination, route `/` to frontend, `/api/` to gateway pool).
- **Frontend**: Static files from `frontend/dist/` (or CDN).
- **Gateway**: 2+ stateless nodes; in-memory entitlement cache only.
- **Backend**: 2+ Laravel nodes; shared MySQL (and optional Redis for sessions/cache).

### Load balancer Nginx (example)

```nginx
upstream gateway {
    least_conn;
    server gateway1.internal:4000;
    server gateway2.internal:4000;
}

server {
    listen 443 ssl http2;
    server_name quenyx.net;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://frontend-cdn-or-origin;
    }

    location /api/ {
        proxy_pass http://gateway;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Authorization $http_authorization;
    }
}
```

### Gateway nodes

On each node:

```bash
cd gateway
npm ci
npm run build
```

Set `BACKEND_BASE_URL` to the backend pool or load balancer URL. After code changes: rebuild then restart the gateway service.

### Backend nodes

On each node:

```bash
cd backend
composer install --no-dev --optimize-autoloader
# .env: shared DB, same APP_KEY on all nodes
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

### Health and monitoring

- Gateway: `GET /health` → `{"status":"ok","service":"gateway"}` (and optionally `GET /ready` for Nagios).
- Backend: `GET /api/health`.
- Monitor ports 4000 (gateway) and 8000 (backend); set alerts on 502/503 and DB connectivity.

---

## Production readiness

- **Backend:** Use `APP_ENV=production`, `APP_DEBUG=false`, and a strong `APP_KEY`. Run `php artisan config:cache` and `route:cache` after deployment. Ensure `APP_URL` matches the public URL (HTTPS). Do not expose `.env` or storage.
- **Frontend:** Build with `npm run build`; set `VITE_API_BASE_URL` to your API base (e.g. `https://your-domain.com` so that relative `/api/` requests go to the same origin). Do not ship source maps in production if you want to hide source.
- **Gateway:** No secrets in logs; use env vars for `BACKEND_BASE_URL`. Restart after any code or config change.
- **Security:** HTTPS only in production; restrict CORS to your frontend origin; change or remove seeded default credentials before go-live.
- **Errors:** In production, Laravel should not display stack traces to users (controlled by `APP_DEBUG`). Frontend can show a generic “Something went wrong” and log details client-side or to an error reporting service.

---

## Production environment checklist

Before going live:

| Item | Action |
|------|--------|
| **Backend .env** | `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY`, correct `APP_URL` (HTTPS), `DB_DATABASE=quenyx_dev`, `DB_USERNAME=quenyx`, `DB_PASSWORD` |
| **Config validation** | Run `php artisan quenyx:config-check` (use `--strict` in CI). It must pass before go-live |
| **CORS** | Set `CORS_ALLOWED_ORIGINS` to the exact frontend origin(s) (no `*`); set `SANCTUM_STATEFUL_DOMAINS`. `CORS_SUPPORTS_CREDENTIALS=true` requires an explicit origin |
| **Token expiry** | `SANCTUM_TOKEN_EXPIRATION_MINUTES` set (default 7 days). Schedule runs `sanctum:prune-expired` daily |
| **Security headers** | `SECURITY_HEADERS_ENABLED=true`; HSTS enabled (served over HTTPS). CSP/Referrer/Permissions policies applied by `SecurityHeaders` middleware |
| **Login protection** | `AUTH_LOGIN_MAX_ATTEMPTS` / `AUTH_REGISTER_MAX_ATTEMPTS` tuned; login is rate-limited per email + IP |
| **Sessions** | `SESSION_SECURE_COOKIE=true` (HTTPS only) |
| **Frontend build** | `VITE_API_BASE_URL` set to your API base (e.g. `https://your-domain/api`) so requests go to gateway |
| **Seeded credentials** | Set `SEED_ADMIN_PASSWORD` before seeding; rotate after first login if required |
| **Gateway internal secret** | Set strong `GATEWAY_INTERNAL_SECRET` in both backend and gateway env; deployment should fail if missing |
| **Workspaces** | Seeder creates only **Production Env** and **Staging Env**; adjust in `ProjectSeeder` if needed |
| **HTTPS** | Use Nginx (or load balancer) with SSL; redirect HTTP → HTTPS |
| **Gateway** | `BACKEND_BASE_URL` must point to backend (e.g. `http://127.0.0.1:8000` or internal LB URL) |
| **No dev deps** | Backend: `composer install --no-dev`. Frontend/gateway: use built assets; no dev servers in production |
| **Laravel scheduler** | Add crontab for `php artisan schedule:run` (see §7). Without it, QynSight checks never run and "Last poll: never" appears |

---

## Real testing before go-live

Run these flows on a staging or production build:

1. **Login** — Log in with production-ready credentials.
2. **Workspace** — Switch workspace (Workspace dropdown); confirm Dashboard and Observe data change (or show empty state for the other workspace).
3. **Empty state** — In a workspace with no hosts, open Real-time Monitoring and Dashboard; confirm “No hosts in this workspace” and “Add hosts” CTA.
4. **Add host** — In Monitored Targets, add a host (name + address); save.
5. **Observe data** — Open Real-time Monitoring (and Dashboard); confirm host appears (or service totals update once services are added).
6. **Infrastructure Map** — Open Map; confirm hosts appear; test export (JSON/PNG).
7. **Integrations** — Open Integrations; configure webhook (optional); confirm settings save.
8. **Getting started** — Open **Getting started** from sidebar; confirm steps and links work.

If all pass, the platform is ready for production use.

---

## Reproducible installs

- **Frontend:** Commit `package-lock.json`; use `npm ci` in CI and deployment.
- **Backend:** Use `composer install --no-dev --optimize-autoloader` with a locked `composer.lock`.
- **Gateway:** Use `npm ci` and `npm run build`; always restart after rebuilding.

---

## Updating a deployment

1. **Backend:** `git pull`, `composer install --no-dev`, `php artisan migrate --force`, `php artisan config:cache` (and route/view cache as needed), restart app.
2. **Frontend:** `git pull`, `npm ci`, `npm run build`; refresh static hosting (or CDN).
3. **Gateway:** `git pull`, `npm ci`, `npm run build`, restart gateway service.

---

## License

**PROPRIETARY SOFTWARE - Copyright (c) 2026 Quenyx CO. All rights reserved.**
