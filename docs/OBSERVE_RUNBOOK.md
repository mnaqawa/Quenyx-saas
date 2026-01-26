# ShieldObserve Runbook - Approach 2 (Targets → Publish → Reload → Poll)

This runbook provides step-by-step commands for verifying and troubleshooting the ShieldObserve module's integration with Nagios using Approach 2.

## Prerequisites

1. **Docker Compose** with Nagios running:
   ```bash
   docker-compose -f docker-compose.nagios.yml up -d
   ```

2. **Environment Variables** configured:
   - Backend `.env`: `GATEWAY_BASE_URL`, `GATEWAY_INTERNAL_SECRET`, `OBSERVE_AUTO_PUBLISH_NAGIOS=true`
   - Gateway `.env`: `GATEWAY_INTERNAL_SECRET`, `NAGIOS_CONFIG_DIR`, `NAGIOS_CONTAINER_NAME`, `NAGIOS_BASE_URL`, `NAGIOS_USER`, `NAGIOS_PASS`

3. **Migrations** run:
   ```bash
   cd backend
   php artisan migrate
   ```

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

```bash
# Check workspace config file was created
ls -la nagios/config/workspaces/84.cfg

# View config content (should show ws84- prefixed host names)
cat nagios/config/workspaces/84.cfg

# Check portshield.cfg exists
cat nagios/config/portshield.cfg

# Verify Nagios base config includes portshield.cfg
docker exec nagios-core grep -n "portshield" /opt/nagios/etc/nagios.cfg

# Expected: Line showing cfg_file=/opt/nagios/etc/objects/portshield/portshield.cfg

# Verify workspace configs are accessible in container
docker exec nagios-core ls -la /opt/nagios/etc/objects/portshield/workspaces/
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

### Issue 2: Nagios Config Not Loaded

**Symptoms:**
- Config files exist in `nagios/config/workspaces/` but Nagios doesn't see them
- `statusjson.cgi` doesn't show workspace hosts

**Fixes:**
1. Check Docker volume mount:
   ```bash
   docker exec nagios-core ls -la /opt/nagios/etc/objects/portshield/workspaces/
   ```

2. Verify `portshield.cfg` exists:
   ```bash
   docker exec nagios-core cat /opt/nagios/etc/objects/portshield/portshield.cfg
   ```

3. Check Nagios base config includes portshield.cfg:
   ```bash
   docker exec nagios-core grep "portshield" /opt/nagios/etc/nagios.cfg
   ```
   
   If missing, the gateway should auto-add it on next config write. Or manually:
   ```bash
   docker exec nagios-core sh -c "echo 'cfg_file=/opt/nagios/etc/objects/portshield/portshield.cfg' >> /opt/nagios/etc/nagios.cfg"
   ```

4. Validate Nagios config:
   ```bash
   docker exec nagios-core /usr/local/nagios/bin/nagios -v /opt/nagios/etc/nagios.cfg
   ```

5. Reload Nagios:
   ```bash
   # Via gateway
   curl -X POST http://127.0.0.1:4000/internal/engines/nagios/reload \
     -H "x-internal-secret: dev-secret-change-in-production"
   
   # Or manually
   docker exec nagios-core kill -HUP 1
   # Or
   docker restart nagios-core
   ```

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

- [ ] Gateway internal routes accessible (`/_debug/routes`)
- [ ] Targets can be created via API (`PUT /observe/targets`)
- [ ] Config files created in `nagios/config/workspaces/{id}.cfg`
- [ ] `portshield.cfg` exists and includes workspace directory
- [ ] Nagios base config includes `portshield.cfg`
- [ ] Reload endpoint returns `validated: true, reloaded: true`
- [ ] Nagios `statusjson.cgi` shows workspace-prefixed hosts (`ws{id}-...`)
- [ ] Poller successfully stores services with workspace scoping
- [ ] API returns only workspace-scoped services
- [ ] Publish status tracked in `observe_meta` (`last_publish_at`, `last_publish_success`)

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
NAGIOS_CONFIG_DIR=./nagios/config
NAGIOS_CONTAINER_NAME=nagios-core
NAGIOS_BASE_URL=http://127.0.0.1:8080/nagios
NAGIOS_USER=nagiosadmin
NAGIOS_PASS=nagios
```

## Build Verification

```bash
# Frontend
cd frontend
npm run build

# Gateway
cd gateway
npm run build

# Backend
cd backend
php artisan test --filter=ObserveTest
```
