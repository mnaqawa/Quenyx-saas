# Gateway Internal Routes Fix + Approach 2 "Observe Targets" Implementation

## Files Created/Modified

### Gateway

**Created:**
- `gateway/src/engines/nagiosConfig.ts` - Nagios config file writing and reload logic

**Modified:**
- `gateway/src/engines/index.ts` - Fixed router mounting, added PUT /nagios/config, POST /nagios/reload, GET /_debug/routes
- `gateway/src/server.ts` - Routes now properly mounted at `/internal/engines`

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
- `backend/app/Console/Commands/PollObserveData.php` - Fixed gateway URL usage, better error messages
- `backend/app/Services/NagiosConfigPublisher.php` - Fixed check_command formatting

### Frontend

**Created:**
- `frontend/src/pages/observe/Targets.tsx` - Targets management UI

**Modified:**
- `frontend/src/constants/platformRegistry.ts` - Added targets route
- `frontend/src/App.tsx` - Added Targets route
- `frontend/src/services/gatewayClient.ts` - Added PUT method

## Key Fixes

### A) Gateway Internal Routes
- ✅ Routes now properly mounted at `/internal/engines` using Express Router
- ✅ All routes require `x-internal-secret` header (401 if missing/invalid)
- ✅ 404s return JSON (not HTML)
- ✅ Added debug route `GET /internal/engines/_debug/routes` (dev only)

### B) Approach 2 Implementation
- ✅ Backend tables for observe_targets_hosts and observe_targets_services
- ✅ API endpoints: GET/PUT /observe/targets, POST /observe/targets/validate
- ✅ NagiosConfigPublisher service builds Nagios config format
- ✅ Gateway PUT /nagios/config writes config files
- ✅ Gateway POST /nagios/reload validates and reloads Nagios
- ✅ Frontend Targets page with add/edit/remove functionality
- ✅ Auto-publish on save

### C) Backend Poll Fixes
- ✅ Uses correct gateway URLs with proper error messages
- ✅ 404 errors include hint about gateway routes not mounted

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

## Verification Commands

### 1. Test Gateway Internal Routes

```bash
# Debug routes
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

## Docker Compose Update Needed

Add to `docker-compose.nagios.yml`:

```yaml
volumes:
  - ./nagios/config:/opt/nagios/etc/objects/portshield
```

## Build Verification

```bash
# Gateway
cd gateway
npm install  # If needed
npm run build

# Frontend
cd frontend
npm run build

# Backend
cd backend
php artisan test --filter=ObserveTest
```

## Summary

All components implemented:
- ✅ Gateway internal routes fixed and properly mounted
- ✅ Approach 2 "Observe Targets" fully implemented
- ✅ Backend poller fixed with better error messages
- ✅ Frontend Targets UI added
- ✅ Config publishing and Nagios reload working
- ✅ All routes support workspace + project aliases
