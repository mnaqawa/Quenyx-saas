# ShieldObserve Runbook - Approach 2 (Targets → Publish → Reload → Poll)

This runbook provides step-by-step commands for verifying and troubleshooting the ShieldObserve module's integration with Nagios using Approach 2.

## Prerequisites

1. **Run Migrations (REQUIRED):**
   ```bash
   cd backend
   php artisan migrate
   ```
   
   **Important:** The observe targets feature requires the following tables:
   - `observe_targets_hosts`
   - `observe_targets_services`
   - `observe_services`
   - `observe_meta`
   
   If you see "Table doesn't exist" errors, run migrations first.

2. **Docker Compose** with Nagios running:
   ```bash
   docker-compose -f docker-compose.nagios.yml up -d
   ```

3. **Docker Socket Permissions (for gateway reload):**
   ```bash
   # Add gateway service user to docker group (if running as systemd service)
   sudo usermod -aG docker portshield
   # Or run gateway as a user that has Docker access
   ```
   
   **Nagios reload when Docker unavailable:** Config is still written when Docker is unavailable; only reload is skipped. The reload API returns `reload_skipped: true` and a clear message. To apply config: run `docker exec nagios-core kill -HUP 1` or `docker restart nagios-core` as a user with Docker access, or grant the gateway user Docker access (e.g. `usermod -aG docker portshield`).

4. **Environment Variables** configured:
   - Backend `.env`: `GATEWAY_BASE_URL`, `GATEWAY_INTERNAL_SECRET`, `OBSERVE_AUTO_PUBLISH_NAGIOS=true`
   - Gateway `.env`: `GATEWAY_INTERNAL_SECRET`, `NAGIOS_CONFIG_DIR`, `NAGIOS_CONTAINER_NAME`, `NAGIOS_BASE_URL`, `NAGIOS_USER`, `NAGIOS_PASS`

5. **NAGIOS_CONFIG_DIR and permissions (required when gateway runs as `portshield`):**
   - **Use an absolute path.** Example: `NAGIOS_CONFIG_DIR=/var/www/portshield/portshield-saas/nagios/config`
   - The path must be the same directory that `docker-compose.nagios.yml` mounts into the container as `/opt/nagios/etc/objects/portshield`. If compose uses `./nagios/config` relative to the project root, set `NAGIOS_CONFIG_DIR` to the absolute project path: `/var/www/portshield/portshield-saas/nagios/config`.
   - Create the directory and make it writable by the gateway user (`portshield`):
     ```bash
     NAGIOS_BASE=/var/www/portshield/portshield-saas/nagios
     sudo mkdir -p "$NAGIOS_BASE/config/workspaces"
     sudo chown -R portshield:portshield "$NAGIOS_BASE"
     sudo chmod -R 775 "$NAGIOS_BASE"
     ```
   - If you use a relative `NAGIOS_CONFIG_DIR` (e.g. `./nagios/config`) and systemd `WorkingDirectory` is the gateway dir, the gateway will write under the wrong place (e.g. `/var/www/.../gateway/nagios/config/...`) and may hit permission errors when switching to the correct path. Always set `NAGIOS_CONFIG_DIR` to the absolute host path.
   - **Diagnostic:** PUT `/internal/engines/nagios/config` returns `written_path` in the JSON response. Use it to confirm where the gateway wrote the file (e.g. `.../nagios/config/workspaces/84.cfg`).

## Verification Steps

### Step 1: Verify Gateway Internal Routes

```bash
# List all registered internal routes
curl -s -H "x-internal-secret: dev-secret-change-in-production" \
  http://127.0.0.1:4000/internal/engines/_debug/routes | jq

# Expected output:
# {
#   "success": true,
#   "routes": [
#     "GET /internal/engines/nagios/summary",
#     "GET /internal/engines/nagios/services",
#     "PUT /internal/engines/nagios/config",
#     "POST /internal/engines/nagios/reload",
#     "GET /internal/engines/_debug/routes"
#   ]
# }

# Test summary endpoint
curl -i -H "x-internal-secret: dev-secret-change-in-production" \
  http://127.0.0.1:4000/internal/engines/nagios/summary

# Test services endpoint
curl -i -H "x-internal-secret: dev-secret-change-in-production" \
  http://127.0.0.1:4000/internal/engines/nagios/services
```

### Step 2: Create Targets

```bash
# Get authentication token
TOKEN=$(curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}' | jq -r '.data.token')

# Create targets for workspace 84
curl -X PUT http://127.0.0.1:8000/api/workspaces/84/observe/targets \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "hosts": [
      {
        "name": "web-server-01",
        "address": "192.168.1.10",
        "check_command": "check-host-alive",
        "enabled": true,
        "services": [
          {
            "name": "HTTP",
            "check_command": "check_http",
            "check_args": ["-H", "example.com"],
            "enabled": true
          },
          {
            "name": "Ping",
            "check_command": "check_ping",
            "enabled": true
          }
        ]
      }
    ]
  }'

# Expected: {"success":true,"message":"Targets updated and published to Nagios"}
```

### Step 3: Verify Nagios Config Files

After publish, the PUT `/internal/engines/nagios/config` response includes `written_path` (absolute path where the gateway wrote the file). Use it to confirm the file landed at the intended host path (e.g. `/var/www/portshield/portshield-saas/nagios/config/workspaces/84.cfg`).

**How workspace configs are loaded:** Nagios loads workspace configs via a **cfg_dir** directive in the **main** `/opt/nagios/etc/nagios.cfg`. The gateway ensures this line exists in `nagios.cfg`:

- `cfg_dir=/opt/nagios/etc/objects/portshield/workspaces`

Workspace configs are **not** loaded via `cfg_file=.../portshield.cfg`. The directive `cfg_dir` is valid only in the main `nagios.cfg`; if you put `cfg_dir` inside a file that is itself included via `cfg_file`, Nagios reports "Unexpected token or statement" and loads only localhost.

```bash
# On host: check workspace config exists at the path returned in written_path
ls -la /var/www/portshield/portshield-saas/nagios/config/workspaces/84.cfg

# View config content (should show ws84- prefixed host names)
cat /var/www/portshield/portshield-saas/nagios/config/workspaces/84.cfg

# Inside container: must see the same file (bind mount)
docker exec nagios-core ls -l /opt/nagios/etc/objects/portshield/workspaces/84.cfg

# Verify nagios.cfg loads workspaces via cfg_dir (required)
docker exec nagios-core grep -n "portshield" /opt/nagios/etc/nagios.cfg
# Expected: A line containing cfg_dir=/opt/nagios/etc/objects/portshield/workspaces
# There must NOT be: cfg_file=/opt/nagios/etc/objects/portshield/portshield.cfg (see Issue 2 if present)
```

### Step 4: Verify Nagios Reload

```bash
# Test reload endpoint
curl -X POST http://127.0.0.1:4000/internal/engines/nagios/reload \
  -H "x-internal-secret: dev-secret-change-in-production" | jq

# Expected output:
# {
#   "success": true,
#   "validated": true,
#   "reloaded": true,
#   "method": "hup" or "restart",
#   "message": "...",
#   "stdout": "...",
#   "stderr": "..."
# }
```

### Step 5: Verify Nagios Sees Targets

```bash
# Query Nagios API for service list
curl -s -u nagiosadmin:nagios \
  "http://127.0.0.1:8080/nagios/cgi-bin/statusjson.cgi?query=servicelist" | jq

# Look for services with host_name matching ws84-web-server-01 (workspace-prefixed)

# Alternative: Check host list
curl -s -u nagiosadmin:nagios \
  "http://127.0.0.1:8080/nagios/cgi-bin/statusjson.cgi?query=hostlist" | jq '.data.hostlist[] | select(.name | startswith("ws84-"))'
```

### Step 6: Poll Data

```bash
cd backend

# Poll specific workspace
php artisan observe:poll --workspace_id=84

# Expected output:
# Polling workspace 84 (Workspace Name)...
# Successfully polled 84: X services

# Verify data was stored
php artisan tinker
>>> \App\Models\ObserveService::where('workspace_id', 84)->count()
>>> \App\Models\ObserveService::where('workspace_id', 84)->where('host_name', 'like', 'ws84-%')->count()
>>> \App\Models\ObserveMeta::where('workspace_id', 84)->first()
```

### Step 7: Verify API Returns Scoped Data

```bash
# Get services via API (should only return ws84- prefixed hosts)
curl -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/workspaces/84/observe/services?limit=50" | jq

# Verify publish status
curl -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/workspaces/84/observe/summary" | jq

# Check observe_meta for publish status
php artisan tinker
>>> \App\Models\ObserveMeta::where('workspace_id', 84)->first(['last_publish_at', 'last_publish_success', 'last_publish_error'])
```

### Step 8: Manual Publish (if auto-publish disabled)

```bash
cd backend

# Publish config manually
php artisan observe:nagios:publish --workspace_id=84

# Expected: "Config published successfully"
```

## Common Issues and Fixes

### Issue 1: Gateway Routes Return 404

**Symptoms:**
- `curl /internal/engines/nagios/services` returns 404
- Backend poller fails with "Gateway internal route not found"

**Fixes:**
1. Verify gateway is running:
   ```bash
   curl http://127.0.0.1:4000/health
   ```

2. Check routes are registered:
   ```bash
   curl -H "x-internal-secret: dev-secret-change-in-production" \
     http://127.0.0.1:4000/internal/engines/_debug/routes
   ```

3. Verify secret matches in both gateway and backend `.env` files

4. Restart gateway:
   ```bash
   cd gateway
   npm run build
   # Restart gateway process
   ```

### Issue 2: Nagios Config Not Loaded (hostlist only shows localhost)

**Symptoms:**
- Publish succeeds but Nagios hostlist only shows localhost
- Workspace hosts (e.g. `ws84-*`) do not appear in `statusjson.cgi?query=hostlist`

**Cause:** Workspace configs must be loaded via **cfg_dir** in the main `nagios.cfg`. The directive `cfg_dir` is valid only in the main config file. If `nagios.cfg` included `cfg_file=/opt/nagios/etc/objects/portshield/portshield.cfg` and that file contained `cfg_dir=...`, Nagios reports "Unexpected token or statement in file '...portshield.cfg' on line 6" and loads only localhost.

**Verification steps:**

1. **Confirm workspace cfg exists inside the container** (must match host path via bind mount):
   ```bash
   docker exec nagios-core ls -l /opt/nagios/etc/objects/portshield/workspaces/84.cfg
   ```
   If "No such file", the host path is wrong or the gateway wrote elsewhere. Check PUT response `written_path` and `NAGIOS_CONFIG_DIR`.

2. **Ensure `nagios.cfg` loads workspaces via cfg_dir (not cfg_file to portshield.cfg):**
   ```bash
   docker exec nagios-core grep "portshield" /opt/nagios/etc/nagios.cfg
   ```
   **Expected:** A line `cfg_dir=/opt/nagios/etc/objects/portshield/workspaces`.  
   **Invalid (remove if present):** `cfg_file=/opt/nagios/etc/objects/portshield/portshield.cfg` — that pattern leads to "Unexpected token" because portshield.cfg would contain cfg_dir, which is not allowed inside an included object file.

**Troubleshooting: Remove old cfg_file line if present**

If you see `cfg_file=/opt/nagios/etc/objects/portshield/portshield.cfg` in `nagios.cfg`, remove it and ensure `cfg_dir=.../workspaces` is in `nagios.cfg` instead:

   ```bash
   # Remove the legacy cfg_file line (invalid pattern)
   docker exec nagios-core sed -i '\|cfg_file=/opt/nagios/etc/objects/portshield/portshield.cfg|d' /opt/nagios/etc/nagios.cfg

   # Ensure cfg_dir for workspaces exists (add if missing)
   docker exec nagios-core sh -c "grep -q 'cfg_dir=/opt/nagios/etc/objects/portshield/workspaces' /opt/nagios/etc/nagios.cfg || (echo '' >> /opt/nagios/etc/nagios.cfg && echo '# PortShield workspace configs (auto-added)' >> /opt/nagios/etc/nagios.cfg && echo 'cfg_dir=/opt/nagios/etc/objects/portshield/workspaces' >> /opt/nagios/etc/nagios.cfg)"

   # Reload Nagios
   docker exec nagios-core kill -HUP 1
   # or: curl -X POST http://127.0.0.1:4000/internal/engines/nagios/reload -H "x-internal-secret: ..."
   ```

   On minimal images without `sed -i`, edit in place or replace the file (e.g. `docker exec ... cat /opt/nagios/etc/nagios.cfg` on host, edit, then `docker cp` back).

3. **Validate and reload:**
   ```bash
   docker exec nagios-core /usr/local/nagios/bin/nagios -v /opt/nagios/etc/nagios.cfg
   docker exec nagios-core kill -HUP 1
   ```

4. **Re-check hostlist:**
   ```bash
   curl -s -u nagiosadmin:nagios "http://127.0.0.1:8080/nagios/cgi-bin/statusjson.cgi?query=hostlist" | jq '.data.hostlist[] | select(.name | startswith("ws84-"))'
   ```
   Expect at least one host. Then `.../services?host_prefix=ws84-` should return >0 and poll should insert `observe_services` rows.

### Issue 3: Reload Method Not Working

**Symptoms:**
- Reload endpoint returns `reloaded: false`
- Nagios doesn't pick up new configs

**Fixes:**
1. Check reload response:
   ```bash
   curl -X POST http://127.0.0.1:4000/internal/engines/nagios/reload \
     -H "x-internal-secret: dev-secret-change-in-production" | jq
   ```

2. If `method: "restart"` was used, wait for container to fully restart:
   ```bash
   docker ps | grep nagios-core
   ```

3. If HUP fails, manually restart:
   ```bash
   docker restart nagios-core
   ```

4. Verify Nagios process:
   ```bash
   docker exec nagios-core pgrep -x nagios
   ```

### Issue 4: Workspace Scoping Issues (Data Leakage)

**Symptoms:**
- Workspace A sees services from Workspace B
- Host names don't have `ws{id}-` prefix

**Fixes:**
1. Verify host names in generated config:
   ```bash
   cat nagios/config/workspaces/84.cfg | grep host_name
   # Should show: host_name ws84-...
   ```

2. Check poller filters correctly:
   ```bash
   php artisan tinker
   >>> \App\Models\ObserveService::where('workspace_id', 84)->pluck('host_name')
   # All should start with ws84-
   ```

3. Verify API filters:
   ```bash
   curl -H "Authorization: Bearer $TOKEN" \
     "http://127.0.0.1:8000/api/workspaces/84/observe/services" | jq '.data.items[].host'
   # All should start with ws84-
   ```

### Issue 5: Validation Errors

**Symptoms:**
- `PUT /observe/targets` returns 422 with validation errors
- Invalid check commands rejected

**Fixes:**
1. Check allowed commands:
   - Hosts: `check-host-alive`, `check_ping`
   - Services: `check_ping`, `check_http`, `check_load`, `check_users`, `check_disk`

2. Verify name sanitization:
   - Names are sanitized to `[A-Za-z0-9_-]`
   - Spaces replaced with hyphens

3. Check for duplicate names after sanitization

### Issue 6: Auto-Publish Not Working

**Symptoms:**
- Targets saved but Nagios config not updated
- `last_publish_at` is null

**Fixes:**
1. Check environment variable:
   ```bash
   # In backend .env
   OBSERVE_AUTO_PUBLISH_NAGIOS=true
   ```

2. Verify publish was attempted:
   ```bash
   php artisan tinker
   >>> \App\Models\ObserveMeta::where('workspace_id', 84)->first(['last_publish_at', 'last_publish_success', 'last_publish_error'])
   ```

3. Check backend logs:
   ```bash
   tail -f backend/storage/logs/laravel.log | grep -i publish
   ```

4. Manually publish:
   ```bash
   php artisan observe:nagios:publish --workspace_id=84
   ```

## Quick Verification Checklist

- [ ] **NAGIOS_CONFIG_DIR** set to absolute path; dir exists and is writable by `portshield` (`mkdir -p .../workspaces && chown -R portshield:portshield .../nagios && chmod -R 775 .../nagios`)
- [ ] Gateway internal routes accessible (`/_debug/routes`)
- [ ] Targets can be created via API (`PUT /observe/targets`)
- [ ] **Publish:** PUT config returns `written_path`; file exists on host at that path (e.g. `/var/www/portshield/portshield-saas/nagios/config/workspaces/84.cfg`)
- [ ] **Container sees cfg:** `docker exec nagios-core ls -l /opt/nagios/etc/objects/portshield/workspaces/84.cfg` shows the file
- [ ] **nagios.cfg** contains `cfg_dir=/opt/nagios/etc/objects/portshield/workspaces` and does **not** contain `cfg_file=.../portshield.cfg` (see Issue 2 if hostlist only shows localhost)
- [ ] Reload endpoint returns `validated: true, reloaded: true` (or reload manually after auto-fix)
- [ ] **Nagios hostlist includes ws84-***: `statusjson.cgi?query=hostlist` shows workspace-prefixed hosts
- [ ] **Gateway host_prefix=ws84- returns >0:** `.../internal/engines/nagios/services?host_prefix=ws84-` returns `.data` length > 0
- [ ] **Poll inserts >0 rows:** `observe:poll --workspace_id=84` then `ObserveService::where('workspace_id',84)->count() > 0`
- [ ] API returns only workspace-scoped services; publish status in `observe_meta`

## End-to-end verification (workspace 84)

Use these exact commands to confirm Observe Targets → Nagios → Poll → UI for workspace 84. Goal: after setting targets for ws84, gateway `.../services?host_prefix=ws84-` returns data and `/observe/services` UI shows rows.

### 1. Set targets and publish

```bash
# Auth (adjust credentials if needed)
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}' | jq -r '.data.token')

# PUT targets for workspace 84
curl -s -X PUT http://127.0.0.1:8000/api/workspaces/84/observe/targets \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"hosts":[{"name":"web-server-01","address":"192.168.1.10","check_command":"check-host-alive","enabled":true,"services":[{"name":"HTTP","check_command":"check_http","enabled":true},{"name":"Ping","check_command":"check_ping","enabled":true}]}]}' | jq
# Expected: {"success":true,"message":"Targets updated and published to Nagios"}

# If auto-publish is off, publish manually:
cd backend && php artisan observe:nagios:publish --workspace_id=84
```

### 2. Confirm ws84- host exists in Nagios

```bash
# Option A: config file contains ws84- host
grep -E "host_name|define host" nagios/config/workspaces/84.cfg
# Expect lines with ws84-...

# Option B: Nagios API hostlist
curl -s -u nagiosadmin:nagios "http://127.0.0.1:8080/nagios/cgi-bin/statusjson.cgi?query=hostlist" | jq '.data.hostlist[] | select(.name | startswith("ws84-"))'
# Expect at least one host object.

# Option C: container sees workspace config
docker exec nagios-core ls -la /opt/nagios/etc/objects/portshield/workspaces/84.cfg
# Expect file listing (not "No such file").
```

### 3. Gateway host_prefix returns >0 services

```bash
curl -s -H "x-internal-secret: dev-secret-change-in-production" \
  "http://127.0.0.1:4000/internal/engines/nagios/services?host_prefix=ws84-" | jq '.data | length'
# Expect a number > 0.

# Inspect response
curl -s -H "x-internal-secret: dev-secret-change-in-production" \
  "http://127.0.0.1:4000/internal/engines/nagios/services?host_prefix=ws84-" | jq
```

### 4. Poll inserts rows

```bash
cd backend
php artisan observe:poll --workspace_id=84
# Expect: "Successfully polled 84: N services" (N > 0).

# Confirm rows in DB
php artisan tinker --execute="echo \App\Models\ObserveService::where('workspace_id', 84)->where('host_name', 'like', 'ws84-%')->count();"
# Expect integer > 0.
```

### 5. API and UI

```bash
# API returns workspace-scoped services
curl -s -H "Authorization: Bearer $TOKEN" "http://127.0.0.1:8000/api/workspaces/84/observe/services?limit=50" | jq '.data.items | length'
# Expect > 0.

# UI: open /observe/services for workspace 84 and confirm the table shows rows.
```

## Environment Variables Reference

### Backend `.env`
```bash
GATEWAY_BASE_URL=http://127.0.0.1:4000
GATEWAY_INTERNAL_SECRET=dev-secret-change-in-production
OBSERVE_AUTO_PUBLISH_NAGIOS=true  # Default: true in dev
```

### Gateway `.env`
```bash
GATEWAY_INTERNAL_SECRET=dev-secret-change-in-production
# REQUIRED when gateway runs as portshield: use absolute path matching the bind mount source
NAGIOS_CONFIG_DIR=/var/www/portshield/portshield-saas/nagios/config
NAGIOS_CONTAINER_NAME=nagios-core
NAGIOS_BASE_URL=http://127.0.0.1:8080/nagios
NAGIOS_USER=nagiosadmin
NAGIOS_PASS=nagios
```

**Config path and permissions:** Use an absolute path for `NAGIOS_CONFIG_DIR` so gateway writes to the same directory the Nagios container mounts. Ensure that directory exists and is writable by the gateway user (e.g. `portshield`): `mkdir -p /var/www/portshield/portshield-saas/nagios/config/workspaces && chown -R portshield:portshield /var/www/portshield/portshield-saas/nagios && chmod -R 775 /var/www/portshield/portshield-saas/nagios`. PUT `/internal/engines/nagios/config` returns `written_path` in the JSON response so you can confirm where the file was written.

## Database table names (no mismatch)

Observe targets use **plural** table names. Models and code must reference these exactly:

- `observe_targets_hosts` — workspace-defined hosts (ObserveTargetHost `$table`)
- `observe_targets_services` — workspace-defined services on hosts (ObserveTargetService `$table`)
- `observe_services` — polled service state from Nagios (ObserveService)
- `observe_meta` — last poll/publish per workspace/engine (ObserveMeta)

If you see "Table observe_target_hosts doesn't exist" (singular), the code is wrong; use `observe_targets_hosts`.

### observe_services schema (real column names)

The poller and API use **service_name** (not `service_description`). If you see "Unknown column 'service_description' in observe_services", ensure code and any raw SQL use the real column names below.

```sql
-- Real columns (e.g. MySQL)
DESCRIBE observe_services;
```

| Field              | Type            | Null | Key | Default | Extra          |
|--------------------|-----------------|------|-----|---------|----------------|
| id                 | bigint unsigned | NO   | PRI | NULL    | auto_increment |
| workspace_id       | bigint unsigned | NO   | MUL | NULL    |                |
| engine_key         | varchar(50)     | NO   |     | nagios  |                |
| engine_service_key | varchar(255)    | NO   |     | NULL    |                |
| host_name          | varchar(255)    | NO   |     | NULL    |                |
| **service_name**   | varchar(255)    | NO   |     | NULL    |                |
| state              | varchar(20)     | NO   |     | NULL    |                |
| last_check_at      | datetime        | YES  |     | NULL    |                |
| duration_sec       | int             | YES  |     | NULL    |                |
| attempt            | varchar(255)    | YES  |     | NULL    |                |
| output             | text            | YES  |     | NULL    |                |
| perfdata           | text            | YES  |     | NULL    |                |
| created_at         | timestamp       | YES  |     | NULL    |                |
| updated_at         | timestamp       | YES  |     | NULL    |                |

Example insert aligned with schema (use `service_name`, not `service_description`):

```sql
INSERT INTO observe_services (workspace_id, engine_key, engine_service_key, host_name, service_name, state, last_check_at, duration_sec, attempt, output, perfdata, created_at, updated_at)
VALUES (84, 'nagios', 'ws84-PortShield-SaaS::check live', 'ws84-PortShield-SaaS', 'check live', 'ok', NOW(), 0, '1/3', NULL, NULL, NOW(), NOW());
```

## Frontend redeploy (use real API, not fixtures)

For production and dev deployments, the Observe UI must call the real backend (`/api/workspaces/:id/observe/services` and `/observe/summary`), not fixtures.

1. **Set env for production build:**
   - In `frontend/.env.production` set: `VITE_OBSERVE_USE_FIXTURES=false`
   - Or build with: `VITE_OBSERVE_USE_FIXTURES=false npm run build`

2. **Exact redeploy steps:**
   ```bash
   cd frontend
   # Ensure fixtures are off (default if .env.production has VITE_OBSERVE_USE_FIXTURES=false)
   npm run build
   # Deploy the contents of dist/ to your static host / reverse proxy
   ```

3. **Verification:** After deploy, open `/app/workspaces/84/observe/services`. It should show real rows from the backend after polling; if it shows "No services found" with real data in the DB, the frontend build was likely made with `VITE_OBSERVE_USE_FIXTURES=true` or the backend is not reachable.

## Build Verification

```bash
# Frontend (production build uses real Observe API when VITE_OBSERVE_USE_FIXTURES is not 'true')
cd frontend
VITE_OBSERVE_USE_FIXTURES=false npm run build

# Gateway
cd gateway
npm run build

# Backend
cd backend
php artisan test --filter=ObserveTest
```
