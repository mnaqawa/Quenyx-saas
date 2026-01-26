# Gateway Internal Routes + Approach 2 Implementation - Verification Guide

## Files Created/Modified

### Gateway (3 files)
- ✅ `gateway/src/engines/nagiosConfig.ts` (NEW) - Nagios config file writing and reload
- ✅ `gateway/src/engines/index.ts` (MODIFIED) - Fixed router mounting, added all routes
- ✅ `gateway/src/server.ts` (MODIFIED) - Added body parsing for internal routes

### Backend (11 files)
- ✅ `backend/database/migrations/2026_01_25_000022_create_observe_targets_hosts_table.php` (NEW)
- ✅ `backend/database/migrations/2026_01_25_000023_create_observe_targets_services_table.php` (NEW)
- ✅ `backend/app/Models/ObserveTargetHost.php` (NEW)
- ✅ `backend/app/Models/ObserveTargetService.php` (NEW)
- ✅ `backend/app/Http/Controllers/ObserveTargetsController.php` (NEW)
- ✅ `backend/app/Services/NagiosConfigPublisher.php` (NEW)
- ✅ `backend/app/Console/Commands/PublishNagiosConfig.php` (NEW)
- ✅ `backend/routes/api.php` (MODIFIED) - Added targets routes
- ✅ `backend/app/Console/Commands/PollObserveData.php` (MODIFIED) - Fixed gateway URLs
- ✅ `backend/app/Policies/ProjectPolicy.php` (MODIFIED) - Allow admin to edit
- ✅ `backend/config/app.php` (MODIFIED) - Added gateway config

### Frontend (4 files)
- ✅ `frontend/src/pages/observe/Targets.tsx` (NEW)
- ✅ `frontend/src/constants/platformRegistry.ts` (MODIFIED) - Added targets route
- ✅ `frontend/src/App.tsx` (MODIFIED) - Added Targets route
- ✅ `frontend/src/services/gatewayClient.ts` (MODIFIED) - Added PUT method

## Setup Steps

### 1. Run Migrations
```bash
cd backend
php artisan migrate
```

### 2. Environment Variables

**Backend `.env`:**
```bash
GATEWAY_BASE_URL=http://127.0.0.1:4000
GATEWAY_INTERNAL_SECRET=dev-secret-change-in-production
```

**Gateway `.env` (or environment):**
```bash
GATEWAY_INTERNAL_SECRET=dev-secret-change-in-production
NAGIOS_CONFIG_DIR=./nagios/config
NAGIOS_CONTAINER_NAME=nagios-core
NAGIOS_BASE_URL=http://127.0.0.1:8080/nagios
NAGIOS_USER=nagiosadmin
NAGIOS_PASS=nagios
```

### 3. Docker Compose Update

Add to `docker-compose.nagios.yml`:
```yaml
volumes:
  - ./nagios/config:/opt/nagios/etc/objects/portshield
```

## Verification Commands

### Step 1: Verify Gateway Internal Routes

```bash
# Debug route (lists all registered routes)
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

# Expected: 200 OK with JSON response

# Test services endpoint
curl -i -H "x-internal-secret: dev-secret-change-in-production" \
  http://127.0.0.1:4000/internal/engines/nagios/services

# Expected: 200 OK with JSON response
```

### Step 2: Test Backend Poller

```bash
cd backend

# Poll a specific workspace
php artisan observe:poll --workspace_id=84

# Expected output:
# Polling workspace 84 (Workspace Name)...
# Polled 1 workspace(s) successfully, 0 failed

# Verify data was stored
php artisan tinker
>>> \App\Models\ObserveService::where('workspace_id', 84)->count()
>>> \App\Models\ObserveMeta::where('workspace_id', 84)->first()
```

### Step 3: Create Targets and Publish

```bash
# Get auth token
TOKEN=$(curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}' | jq -r '.data.token')

# Create targets via API
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

# Or publish manually
php artisan observe:nagios:publish --workspace_id=84
```

### Step 4: Verify Nagios Config File

```bash
# Check config file was created
ls -la nagios/config/workspaces/84.cfg

# View config content
cat nagios/config/workspaces/84.cfg

# Expected: Nagios config with host and service definitions
```

### Step 5: Verify Nagios Sees New Services

```bash
# Query Nagios API for service list
curl -s -u nagiosadmin:nagios \
  "http://127.0.0.1:8080/nagios/cgi-bin/statusjson.cgi?query=servicelist" | jq

# Look for services with host_name matching your targets (e.g., "web-server-01")
```

### Step 6: Poll and Verify API

```bash
# Poll data (should now include workspace-defined targets)
cd backend
php artisan observe:poll --workspace_id=84

# Get services via API
curl -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/workspaces/84/observe/services?limit=50" | jq

# Expected: JSON response with services array including your targets
```

### Step 7: Test Frontend Targets Page

1. Start frontend dev server:
   ```bash
   cd frontend
   npm run dev
   ```

2. Navigate to: `http://localhost:5173/app/workspaces/84/observe/targets`

3. Verify:
   - Page loads without errors
   - Can add/edit/remove hosts
   - Can add/edit/remove services per host
   - "Save & Publish" button works
   - Success toast appears after save

## Troubleshooting

### Gateway Routes Return 404

1. **Check gateway is running:**
   ```bash
   curl http://127.0.0.1:4000/health
   ```

2. **Check routes are registered:**
   ```bash
   curl -H "x-internal-secret: dev-secret-change-in-production" \
     http://127.0.0.1:4000/internal/engines/_debug/routes
   ```

3. **Verify secret matches:**
   - Gateway: `process.env.GATEWAY_INTERNAL_SECRET`
   - Backend: `config('app.gateway_internal_secret')`
   - Both should be `dev-secret-change-in-production` (or same value)

### Backend Poller Fails

1. **Check gateway URL:**
   ```bash
   # In backend, verify config
   php artisan tinker
   >>> config('app.gateway_url')
   ```

2. **Test gateway connectivity:**
   ```bash
   curl http://127.0.0.1:4000/health
   ```

3. **Check error message:**
   - If 404: "Gateway internal route not found" → Gateway routes not mounted
   - If 401: Secret mismatch
   - If connection refused: Gateway not running

### Nagios Config Not Applied

1. **Check config file exists:**
   ```bash
   ls -la nagios/config/workspaces/84.cfg
   ```

2. **Check Docker volume mount:**
   ```bash
   docker exec nagios-core ls -la /opt/nagios/etc/objects/portshield/workspaces/
   ```

3. **Check Nagios config includes workspace files:**
   ```bash
   docker exec nagios-core cat /opt/nagios/etc/nagios.cfg | grep portshield
   ```

4. **Manually reload Nagios:**
   ```bash
   docker exec nagios-core kill -HUP 1
   ```

## Build Verification

### Frontend
```bash
cd frontend
npm run build
# Expected: ✓ built in X.XXs
```

### Gateway
```bash
cd gateway
npm install  # If TypeScript not installed
npm run build
# Expected: No errors
```

### Backend
```bash
cd backend
php artisan test --filter=ObserveTest
# Expected: All tests pass
```

## Summary

✅ Gateway internal routes fixed and properly mounted at `/internal/engines`
✅ Backend poller uses correct gateway URLs with better error messages
✅ Approach 2 "Observe Targets" fully implemented:
   - Backend tables and models
   - API endpoints (GET/PUT targets)
   - Config publisher service
   - Gateway config writer and reload
   - Frontend Targets page
✅ All routes support workspace + project aliases
✅ Authorization: members can view, admin/owner can edit
