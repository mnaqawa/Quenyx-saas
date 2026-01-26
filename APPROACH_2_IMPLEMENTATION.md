# Approach 2 "Observe Targets" Implementation Summary

## Files Created/Modified

### Gateway

**Created:**
- `gateway/src/engines/nagiosConfig.ts` - Nagios config file writing and reload logic

**Modified:**
- `gateway/src/engines/index.ts` - Fixed router mounting, added PUT /nagios/config, POST /nagios/reload, GET /_debug/routes, proper 404 JSON responses
- `gateway/src/server.ts` - Added body parsing middleware for /internal/engines routes

### Backend

**Created:**
- `backend/database/migrations/2026_01_25_000022_create_observe_targets_hosts_table.php`
- `backend/database/migrations/2026_01_25_000023_create_observe_targets_services_table.php`
- `backend/app/Models/ObserveTargetHost.php`
- `backend/app/Models/ObserveTargetService.php`
- `backend/app/Http/Controllers/ObserveTargetsController.php` - GET/PUT/validate endpoints
- `backend/app/Services/NagiosConfigPublisher.php` - Builds and publishes Nagios config
- `backend/app/Console/Commands/PublishNagiosConfig.php` - Artisan command

**Modified:**
- `backend/routes/api.php` - Added observe targets routes (workspace + project aliases)
- `backend/app/Console/Commands/PollObserveData.php` - Fixed gateway URL usage, better 404 error messages
- `backend/app/Policies/ProjectPolicy.php` - Updated update() to allow admin/owner to edit targets
- `backend/config/app.php` - Added gateway URL and secret config

### Frontend

**Created:**
- `frontend/src/pages/observe/Targets.tsx` - Targets management UI

**Modified:**
- `frontend/src/constants/platformRegistry.ts` - Added targets route
- `frontend/src/App.tsx` - Added Targets route
- `frontend/src/services/gatewayClient.ts` - Added PUT method

## Key Fixes

### A) Gateway Internal Routes (404 Fix)
- ✅ Routes now properly mounted at `/internal/engines` using Express Router
- ✅ All routes require `x-internal-secret` header (401 if missing/invalid)
- ✅ 404s return JSON (not HTML)
- ✅ Added debug route `GET /internal/engines/_debug/routes` (dev only)
- ✅ Body parsing middleware added for PUT requests

### B) Approach 2 Implementation
- ✅ Backend tables for observe_targets_hosts and observe_targets_services
- ✅ API endpoints: GET/PUT /observe/targets, POST /observe/targets/validate
- ✅ NagiosConfigPublisher service builds Nagios config format
- ✅ Gateway PUT /nagios/config writes config files to `./nagios/config/workspaces/{workspaceId}.cfg`
- ✅ Gateway POST /nagios/reload validates and reloads Nagios via Docker
- ✅ Frontend Targets page with add/edit/remove functionality
- ✅ Auto-publish on save
- ✅ Authorization: workspace members can view, admin/owner can edit

### C) Backend Poll Fixes
- ✅ Uses correct gateway URLs with proper error messages
- ✅ 404 errors include hint about gateway routes not mounted
- ✅ Default values for gateway URL and secret

## Environment Variables

### Gateway
```bash
NAGIOS_BASE_URL=http://127.0.0.1:8080/nagios
NAGIOS_USER=nagiosadmin
NAGIOS_PASS=nagios
GATEWAY_INTERNAL_SECRET=dev-secret-change-in-production
NAGIOS_CONFIG_DIR=./nagios/config
NAGIOS_CONTAINER_NAME=nagios-core
```

### Backend
```bash
GATEWAY_BASE_URL=http://127.0.0.1:4000
GATEWAY_INTERNAL_SECRET=dev-secret-change-in-production
```

### Frontend
```bash
VITE_OBSERVE_USE_FIXTURES=false  # Set to false to use real API
```

## Setup Steps

1. **Run migrations:**
   ```bash
   cd backend
   php artisan migrate
   ```

2. **Update docker-compose.nagios.yml** (add volume mount):
   ```yaml
   volumes:
     - ./nagios/config:/opt/nagios/etc/objects/portshield
   ```

3. **Start services:**
   ```bash
   # Terminal 1: Gateway
   cd gateway
   npm install  # If needed
   npm run dev

   # Terminal 2: Backend
   cd backend
   php artisan serve

   # Terminal 3: Frontend (if needed)
   cd frontend
   npm run dev
   ```

## Verification Commands

### 1. Test Gateway Internal Routes

```bash
# Debug routes (dev only)
curl -H "x-internal-secret: dev-secret-change-in-production" \
  http://127.0.0.1:4000/internal/engines/_debug/routes

# Get summary
curl -i -H "x-internal-secret: dev-secret-change-in-production" \
  http://127.0.0.1:4000/internal/engines/nagios/summary

# Get services
curl -i -H "x-internal-secret: dev-secret-change-in-production" \
  http://127.0.0.1:4000/internal/engines/nagios/services
```

### 2. Create Targets and Publish

```bash
# Get auth token
TOKEN=$(curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}' | jq -r '.data.token')

# Create targets
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
          }
        ]
      }
    ]
  }'

# Publish config manually
cd backend
php artisan observe:nagios:publish --workspace_id=84
```

### 3. Verify Nagios Config

```bash
# Check config file exists
ls -la nagios/config/workspaces/84.cfg

# Check Nagios sees the services
curl -s -u nagiosadmin:nagios \
  "http://127.0.0.1:8080/nagios/cgi-bin/statusjson.cgi?query=servicelist" | jq
```

### 4. Poll and Verify API

```bash
# Poll data
cd backend
php artisan observe:poll --workspace_id=84

# Get services via API
curl -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/workspaces/84/observe/services?limit=50"
```

## Architecture

1. **Gateway** (`/internal/engines/nagios/*`):
   - PUT /config: Writes workspace config file to `./nagios/config/workspaces/{workspaceId}.cfg`
   - POST /reload: Validates and reloads Nagios via Docker exec
   - Requires `x-internal-secret` header

2. **Backend Targets** (`/api/workspaces/{id}/observe/targets`):
   - GET: Returns hosts + services for workspace
   - PUT: Upserts targets (replace-style), auto-publishes to Nagios
   - POST /validate: Validates configuration

3. **Backend Polling** (`observe:poll`):
   - Calls gateway internal endpoints
   - Upserts into `observe_services` table
   - Now includes workspace-defined targets from Nagios

4. **Frontend**:
   - Targets page allows defining hosts/services
   - "Save & Publish" button triggers PUT + auto-publish
   - UI matches existing dark theme

## Data Flow

```
Frontend (Targets UI) → Backend API → NagiosConfigPublisher → Gateway → Nagios Config File
                                                                         ↓
Backend Poller ← Gateway ← Nagios API ← Nagios (monitoring workspace targets)
```

## Notes

- Gateway writes config files to host filesystem (bind-mounted into Nagios container)
- Nagios reload uses `kill -HUP 1` in container (PID 1 is Nagios process)
- Config validation happens before reload
- Frontend Targets page uses expandable hosts with nested services
- All endpoints support both `/api/workspaces/` and `/api/projects/` aliases
- Authorization: members can view, admin/owner can edit
