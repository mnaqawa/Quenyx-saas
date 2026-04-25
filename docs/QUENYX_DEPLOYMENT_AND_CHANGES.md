# Quenyx platform: changes, files, and deployment

This document lists what was implemented (rebrand from any legacy “portshield” naming, security hardening for integrations, gateway alignment), which files are involved, and how to deploy and run the stack.

---

## 1. Summary of enhancements

| Area | What changed |
|------|----------------|
| **Branding** | User-facing and internal strings, package names, agent binary and config paths, docs, `LICENSE`, default DB name, seed data, and Go module path use **Quenyx** / `quenyx` consistently. |
| **Integrations API (Laravel)** | `PUT` integration configuration now requires `authorize('update', $project)` (not `view`), so only owners/admins can write settings. All integration list/show/write endpoints require the **`qynintegrations`** module via `EntitlementService` (plan + overrides). |
| **Gateway (Node)** | Entitlement middleware applies to both `/api/projects/:id/integrations* **and** `/api/workspaces/:id/integrations*`. Project ID is parsed from either URL shape. |
| **Frontend localStorage** | Only `quenyx.*` keys (auth token, workspace id, etc.). Users who had pre-migration keys may need to sign in again and re-select a workspace. |
| **Agent (Go)** | Module `github.com/quenyx/agent`; binary `quenyx-agent`; config under `~/.config/quenyx/` (Linux/mac) or `%APPDATA%\quenyx\` (Windows). |
| **Migration filename** | Renamed to remove legacy naming: `2026_02_16_140000_align_legacy_modules_to_quenyx.php` (see §4 if this migration already ran under the old name). |

---

## 2. Files touched (by component)

### Backend (Laravel)

- `app/Http/Controllers/ProjectIntegrationController.php` — `EntitlementService`, `qynintegrations` check, `update` policy for upsert.
- `app/Http/Controllers/AuthController.php`, `AgentController.php`, `AgentDownloadController.php` — already aligned to Quenyx / `quenyx-agent` (verify on merge).
- `app/Constants/AgentConstants.php` — Quenyx protocol labels.
- `app/Console/Commands/EnsureAdminUser.php`, `ResetWorkspaces.php` — Quenyx naming.
- `app/Services/AgentBuildService.php` — builds agent from `agent/` (output paths unchanged: `storage/app/agents/{platform}`).
- `config/database.php` — default `DB_DATABASE` = `quenyx`.
- `composer.json` — description “Quenyx vOPS HUB Backend API”.
- `database/seeders/UserSeeder.php`, `ProjectIntegrationConfigurationSeeder.php` — `admin@quenyx.test`, example URLs.
- `database/migrations/2026_02_16_140000_align_legacy_modules_to_quenyx.php` — **replaces** old file name (see §4).
- `tests/Feature/WorkspacesAliasTest.php` — Pro plan subscription for integrations alias test.
- `README.md`, `TESTING.md`, `DEBUG_LOGGING.md` — dev paths and DB names.

### Frontend (Vite + React)

- `package.json`, `package-lock.json` — package name `quenyx-frontend`.
- `index.html` — title Quenyx vOPS HUB.
- `src/services/apiClient.ts` — `quenyx` storage keys only.
- `src/workspaces/WorkspaceContext.tsx` — `quenyx.selected_workspace_id` only.
- `src/i18n/LanguageContext.tsx` — `quenyx.language` only.
- `src/pages/Profile.tsx` — `quenyx.theme` only.
- `src/pages/observe/InfrastructureMap.tsx` — `quenyx-infra-diagram` storage key.
- `dist/*` (if present) — rebuild (§5) to refresh production bundles.

### Gateway (Node + Express)

- `package.json` — `quenyx-gateway`.
- `src/entitlementGuard.ts` — workspace + project integration paths, `extractProjectId`.
- `src/engines/nagiosConfig.ts` — Quenyx Nagios object paths; temp file `quenyx-nagios-cfg-tmp.cfg`.
- `src/server.ts`, `src/proxy.ts` — unchanged behavior; confirm env in §3.
- `README.md` — service name examples `quenyx-gateway`.

### Agent (Go)

- `go.mod` — `module github.com/quenyx/agent`.
- `main.go`, `internal/cli/*.go`, `internal/config/config.go` — imports, `quenyx-agent`, systemd/launchd names, `com.quenyx.agent`, default Linux user `quenyx` for `install`.
- `package.json`, `build-linux-amd64.sh`, `README.md` — build/copy instructions.

### Repository root and docs

- `LICENSE` — Quenyx proprietary notice.
- `db.config` — example DDL/comments use Quenyx Admin and `admin@quenyx.test`.
- `README.md`, `DEPLOYMENT.md`, and other `*.md` in repo and `docs/` — paths and hostnames (e.g. `quenyx-saas`, `quenyx.net`, `quenyx` Nagios object dir).
- `docker-compose.nagios.yml` — volume `./nagios/config` → `/opt/nagios/etc/objects/quenyx`.
- `.cursor/skills/engine-tooling-integration/SKILL.md` — Quenyx wording.

---

## 3. Environment and configuration (deploy checklist)

Set or verify these for each environment.

### Backend `backend/.env` (typical)

- `APP_NAME` — e.g. `Quenyx` or your product string.
- `APP_URL` — public URL of the Laravel app (or internal URL if only gateway is public).
- `DB_*` — use your real credentials; default database name in config is `quenyx` (override with `DB_DATABASE`).
- `AGENT_SOURCE_PATH` (optional) — path to the `agent/` directory if not next to `backend/`.
- `GATEWAY_BASE_URL` (if used by `AgentController` for install instructions) — public gateway URL.
- `SEED_ADMIN_PASSWORD` — required if you run `UserSeeder` in that environment.

### Gateway

- `GATEWAY_PORT` — e.g. `4000`.
- `BACKEND_BASE_URL` — Laravel, e.g. `http://127.0.0.1:8000`.
- `OBSERVE_ENGINE_URL` (optional) — QynSight observe engine if split.
- `GATEWAY_INTERNAL_SECRET` — required for `/internal/engines/*`.
- Nagios: `NAGIOS_CONFIG_DIR`, `NAGIOS_CONTAINER_NAME`, `NAGIOS_BASE_URL`, credentials as needed (see `gateway` README and `docs/OBSERVE_RUNBOOK.md`).

### Frontend (build-time)

- `VITE_API_BASE_URL` — empty for same-origin, or full API/gateway base URL (see `frontend` README).

---

## 4. Database migration: renamed file (existing production)

The migration that aligns legacy module keys was **renamed** to:

- `2026_02_16_140000_align_legacy_modules_to_quenyx.php`

**New installs:** run `php artisan migrate` as usual.

**If a database already has a row in `migrations` for the old filename**, Laravel would try to run the new file again and fail. **Before** deploying, on that database run **one** of the following.

**Option A — Old migration already executed successfully**

Update the stored migration name to match the new file (MySQL example):

```sql
UPDATE migrations
SET migration = '2026_02_16_140000_align_legacy_modules_to_quenyx'
WHERE migration = '2026_02_16_140000_rename_portshield_to_quenyx_and_modules';
```

**Option B — Old migration never ran**

No row exists with the old name. Running `php artisan migrate` will execute `2026_02_16_140000_align_legacy_modules_to_quenyx` once.

**Option C — Unsure**

Check:

```sql
SELECT migration FROM migrations
WHERE migration LIKE '2026_02_16%'
ORDER BY id;
```

Then apply Option A or B as appropriate.

---

## 5. Deploy and run (step by step)

Use the same order on each app server (adjust paths to your layout, e.g. `/var/www/quenyx-saas`).

### 5.1 Get the code

```bash
cd /var/www
# git pull your branch, or clone fresh
cd quenyx-saas   # or your checkout folder name; renaming portshield-saas to quenyx-saas is optional
```

### 5.2 Backend

```bash
cd backend
composer install --no-dev --optimize-autoloader   # use --dev on staging if you run tests
cp .env.example .env  # if new only; else merge new keys into .env
php artisan key:generate   # if new
php artisan migrate --force
php artisan config:cache
php artisan route:cache
# php artisan db:seed --class=PlanSeeder   # only if you need to refresh plans
```

Run tests in CI or staging:

```bash
php artisan test
```

### 5.3 Build agent binaries (optional, for download API)

On a machine with Go 1.21+:

```bash
cd agent
go mod tidy
go build -o quenyx-agent .
# Linux amd64 to match AgentBuildService on-demand build:
# GOOS=linux GOARCH=amd64 go build -o ../backend/storage/app/agents/linux-amd64 .
# Copy other platforms similarly to backend/storage/app/agents/{platform}
```

Or let Laravel build on first download if `AGENT_SOURCE_PATH` and `go` are available.

### 5.4 Gateway

```bash
cd ../gateway
npm ci
npm run build
# Restart process manager, e.g.:
# sudo systemctl restart quenyx-gateway
# or: pm2 restart quenyx-gateway
```

### 5.5 Frontend

```bash
cd ../frontend
npm ci
npm run build
```

Deploy the contents of `frontend/dist` to your static host (Nginx, S3+CDN, etc.) behind HTTPS.

### 5.6 Long-running services

- **Queue workers:** `php artisan queue:work` (supervisor/systemd).
- **Scheduler:** `* * * * * cd /path/to/backend && php artisan schedule:run` (per `DEPLOYMENT.md` patterns, updated to your paths and service names).
- **Nagios** (if used): ensure host bind mount for `./nagios/config` → `/opt/nagios/etc/objects/quenyx` and `nagios.cfg` includes `cfg_dir=.../quenyx/workspaces` as in runbooks.

### 5.7 Post-deploy checks

- Open the SPA; users may need to **log in again** and **select a workspace** (new localStorage keys).
- `GET` `/api/health` and gateway `GET` `/health`.
- As an owner on a Pro (or entitlements) workspace, open **Integrations**; confirm 403 on free plan without `qynintegrations` if you test that case.
- Download `GET` `{gateway}/api/agents/download/linux-amd64` and confirm filename `quenyx-agent`.

### 5.8 Security and dependency hygiene (recommended, not a single command)

- Run `composer audit` and `npm audit` in `backend/`, `frontend/`, and `gateway/` in CI.
- Keep `APP_DEBUG=false` in production; use HTTPS; restrict who can hit Laravel directly if the gateway is the public edge.

---

## 6. Systemd unit names (examples)

If your units still use old names, rename them to match documentation (e.g. `quenyx-backend.service`, `quenyx-gateway.service`, `quenyx-queue.service`) and update `WorkingDirectory` to your real paths. See `DEPLOYMENT.md` in the repo for full examples (paths should use `quenyx` naming).

---

## 7. Rollback notes

- **Code:** Revert the Git commit or redeploy a previous image.
- **Database:** Use Laravel migration rollback only with care; the module-key migration is data-moving. Prefer restores from backup for production.
- **Gateway:** Reverting `entitlementGuard` restores old path-only behavior for integrations (not recommended without backend alignment).

---

## 8. Support reference (quick)

| Component | Port / role |
|-----------|----------------|
| Laravel | Often `8000` (dev) or PHP-FPM behind Nginx |
| Gateway | `GATEWAY_PORT` (e.g. `4000`); routes `/api/*` to backend, observe to engine if configured |
| SPA | Static files, calls API via `VITE_API_BASE_URL` |

This file is the single handover checklist for the Quenyx rebrand, integration security rules, and deployment. Update `DEPLOYMENT.md` in lockstep with your real hostnames and TLS certificates.
