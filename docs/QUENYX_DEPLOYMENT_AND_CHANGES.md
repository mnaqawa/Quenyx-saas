# Quenyx platform: changes, files, and deployment

This document lists what was implemented (consistent **Quenyx** branding, security hardening for integrations, gateway alignment), which files are involved, and how to deploy and run the stack.

---

## 1. Summary of enhancements

| Area | What changed |
|------|----------------|
| **Branding** | User-facing and internal strings, package names, agent binary and config paths, docs, `LICENSE`, MySQL database/user names (`quenyx_dev`, `quenyx_test`, `quenyx`), seed data, and Go module path use **Quenyx** / `quenyx` consistently. |
| **Integrations API (Laravel)** | `PUT` integration configuration now requires `authorize('update', $project)` (not `view`), so only owners/admins can write settings. All integration list/show/write endpoints require the **`qynintegrations`** module via `EntitlementService` (plan + overrides). |
| **Gateway (Node)** | Entitlement middleware applies to both `/api/projects/:id/integrations/*` and `/api/workspaces/:id/integrations/*`. Project ID is parsed from either URL shape. |
| **Frontend localStorage** | Only `quenyx.*` keys (auth token, workspace id, etc.). Users who had pre-migration keys may need to sign in again and re-select a workspace. |
| **Agent (Go)** | Module `github.com/quenyx/agent`; binary `quenyx-agent`; config under `~/.config/quenyx/` (Linux/mac) or `%APPDATA%\quenyx\` (Windows). |
| **Migration filename** | Renamed to remove legacy naming: `2026_02_16_140000_align_legacy_modules_to_quenyx.php` (see §4 if this migration already ran under the old name). |
| **Tests** | `tests/Feature/ProjectIntegrationAccessTest.php` covers free plan (no integrations), member vs admin upsert, and admin success path. Run `composer test` or `php artisan test` in `backend/`. |

---

## 2. Files touched (by component)

### Backend (Laravel)

- `app/Http/Controllers/ProjectIntegrationController.php` — `EntitlementService`, `qynintegrations` check, `update` policy for upsert.
- `app/Http/Controllers/AuthController.php`, `AgentController.php`, `AgentDownloadController.php` — already aligned to Quenyx / `quenyx-agent` (verify on merge).
- `app/Constants/AgentConstants.php` — Quenyx protocol labels.
- `app/Console/Commands/EnsureAdminUser.php`, `ResetWorkspaces.php` — Quenyx naming.
- `app/Services/AgentBuildService.php` — builds agent from `agent/` (output paths unchanged: `storage/app/agents/{platform}`).
- `config/database.php` — default `DB_DATABASE` = `quenyx`.
- `.env.example` — `DB_DATABASE=quenyx_dev`, `DB_USERNAME=quenyx` (see §3 and §4).
- `scripts/mysql-quenyx-setup.sql` — fresh MySQL databases and `quenyx` user.
- `scripts/migrate-mysql-to-quenyx-databases.sh` — copy legacy DB data into `quenyx_dev` / `quenyx_test`.
- `composer.json` — description “Quenyx vOPS HUB Backend API”.
- `database/seeders/UserSeeder.php`, `ProjectIntegrationConfigurationSeeder.php` — `admin@quenyx.test`, example URLs.
- `database/migrations/2026_02_16_140000_align_legacy_modules_to_quenyx.php` — **replaces** old file name (see §4).
- `tests/Feature/WorkspacesAliasTest.php` — Pro plan subscription for integrations alias test.
- `tests/Feature/ProjectIntegrationAccessTest.php` — integration entitlement + `update` policy (member denied, admin allowed, free plan denied on list).
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
- `DB_CONNECTION=mysql`
- `DB_HOST` — e.g. `127.0.0.1`
- `DB_PORT` — e.g. `3306`
- `DB_DATABASE` — **`quenyx_dev`** on app servers (use `quenyx_test` only for PHPUnit; see `backend/TESTING.md`).
- `DB_USERNAME` — **`quenyx`** (dedicated app user; not `root` in production).
- `DB_PASSWORD` — password for the `quenyx` MySQL user.
- `AGENT_SOURCE_PATH` (optional) — path to the `agent/` directory if not next to `backend/`.
- `GATEWAY_BASE_URL` (if used by `AgentController` for install instructions) — public gateway URL.
- `SEED_ADMIN_PASSWORD` — required if you run `UserSeeder` in that environment.

Copy from template: `cp backend/.env.example backend/.env` then edit credentials.

### Gateway `gateway/.env`

Copy from `gateway/.env.example`:

- `GATEWAY_PORT` — e.g. `4000`.
- `BACKEND_BASE_URL` — Laravel, e.g. `http://127.0.0.1:8000`.
- `OBSERVE_ENGINE_URL` (optional) — QynSight observe engine if split.
- `GATEWAY_INTERNAL_SECRET` — required for `/internal/engines/*`.
- Nagios: `NAGIOS_CONFIG_DIR`, `NAGIOS_CONTAINER_NAME`, `NAGIOS_BASE_URL`, credentials as needed (see `gateway` README and `docs/OBSERVE_RUNBOOK.md`).

### Frontend `frontend/.env` (build-time)

Copy from `frontend/.env.example`. Set `VITE_API_BASE_URL` for local dev; leave empty for same-origin production builds behind Nginx (see `frontend` README).

---

## 4. MySQL: Quenyx database and user names

Quenyx uses these MySQL identifiers:

| Purpose | Database | User |
|---------|----------|------|
| Application (dev/staging/prod app DB) | `quenyx_dev` | `quenyx` |
| PHPUnit / CI test DB | `quenyx_test` | `quenyx` |

### 4.1 Fresh install

```bash
# Edit password in scripts/mysql-quenyx-setup.sql, then:
mysql -u root -p < scripts/mysql-quenyx-setup.sql

cd backend
cp .env.example .env
# Set DB_DATABASE=quenyx_dev, DB_USERNAME=quenyx, DB_PASSWORD=...
php artisan key:generate
php artisan migrate --force
```

### 4.2 Existing server: rename databases and MySQL user

If `SHOW DATABASES;` still lists pre-rebrand database names, migrate data into Quenyx names **before** updating `.env`.

**Stop app traffic briefly** (maintenance window): stop queue workers and optionally put Nginx in maintenance mode.

**A — Copy data into new databases (recommended)**

```bash
cd /var/www/quenyx-saas
chmod +x scripts/migrate-mysql-to-quenyx-databases.sh

export MYSQL_ROOT_USER=root
export LEGACY_DEV_DB=your_current_dev_database
export LEGACY_TEST_DB=your_current_test_database   # omit if unused

./scripts/migrate-mysql-to-quenyx-databases.sh
```

**B — Create or rename MySQL user**

If you already have an app user with the old name, rename it (MySQL 5.7.8+):

```sql
RENAME USER 'old_app_user'@'localhost' TO 'quenyx'@'localhost';
RENAME USER 'old_app_user'@'127.0.0.1' TO 'quenyx'@'127.0.0.1';
```

Or create fresh (see `scripts/mysql-quenyx-setup.sql`).

**C — Grant on new databases**

```sql
GRANT ALL PRIVILEGES ON quenyx_dev.* TO 'quenyx'@'localhost';
GRANT ALL PRIVILEGES ON quenyx_test.* TO 'quenyx'@'localhost';
FLUSH PRIVILEGES;
```

**D — Update `backend/.env`**

```env
DB_DATABASE=quenyx_dev
DB_USERNAME=quenyx
DB_PASSWORD=your_password
```

**E — Clear config cache and verify**

```bash
cd backend
php artisan config:clear
php artisan migrate --force
php artisan db:show
```

**F — Drop legacy databases** (only after the app works against `quenyx_dev`):

```sql
DROP DATABASE your_old_dev_database;
DROP DATABASE your_old_test_database;   -- if applicable
```

**G — Rebuild agent binaries** (removes legacy strings embedded in old builds):

```bash
rm -rf backend/storage/app/agents/.gocache
cd agent
GOOS=linux GOARCH=amd64 go build -o ../backend/storage/app/agents/linux-amd64 .
# Repeat for other platforms you serve from AgentDownloadController
```

### 4.3 Migration filename alignment (existing production)

The migration that aligns legacy module keys was **renamed** to:

- `2026_02_16_140000_align_legacy_modules_to_quenyx.php`

**New installs:** run `php artisan migrate` as usual.

**If a database already has a row in `migrations` for a different February 2026 filename**, Laravel would try to run the new file again and fail. **Before** deploying, on that database run **one** of the following.

**Option A — Migration already executed under an older filename**

Normalize the stored name (MySQL example):

```sql
UPDATE migrations
SET migration = '2026_02_16_140000_align_legacy_modules_to_quenyx'
WHERE migration LIKE '2026_02_16_140000_%'
  AND migration <> '2026_02_16_140000_align_legacy_modules_to_quenyx';
```

**Option B — Migration never ran**

No row exists for `2026_02_16_140000_%`. Running `php artisan migrate` will execute `2026_02_16_140000_align_legacy_modules_to_quenyx` once.

**Option C — Unsure**

Check:

```sql
SELECT migration FROM migrations
WHERE migration LIKE '2026_02_16%'
ORDER BY id;
```

Then apply Option A or B as appropriate.

### 4.4 Verify branding in the checkout

On the server (excludes git metadata and compiled agent artifacts):

```bash
cd /var/www/quenyx-saas
grep -R -i --exclude-dir=.git --exclude-dir=node_modules --exclude-dir=vendor \
  --exclude-dir=.gocache --exclude='*-amd64' --exclude='*-arm64' \
  'quenyx' backend/.env docs/ README.md | head
```

Expected: `quenyx` in `backend/.env` (`DB_DATABASE=quenyx_dev`, `DB_USERNAME=quenyx`). No pre-rebrand product slug in source, docs, or env.

**Git remote URL:** If `.git/config` still points at an old GitHub repository name, update with `git remote set-url origin <new-quenyx-repo-url>` — that is server-local and not stored in this repo.

**Agent binaries:** Rebuild after rebrand (§4.2 G) so download artifacts do not embed legacy build metadata.

---

## 5. Deploy and run (step by step)

Use the same order on each app server (adjust paths to your layout, e.g. `/var/www/quenyx-saas`).

### 5.1 Get the code

```bash
cd /var/www
# git pull your branch, or clone fresh
cd quenyx-saas   # or your checkout folder name
```

### 5.2 Backend

```bash
cd backend
composer install --no-dev --optimize-autoloader   # use --dev on staging if you run tests
cp .env.example .env  # if new only; else merge DB_* and other keys into .env
# Set DB_DATABASE=quenyx_dev, DB_USERNAME=quenyx, DB_PASSWORD=...
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
