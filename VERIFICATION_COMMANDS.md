# ShieldObserve Approach 2 - End-to-End Verification Commands

## Prerequisites

1. **Start Nagios:**
   ```bash
   docker-compose -f docker-compose.nagios.yml up -d
   ```

2. **Environment Variables:**
   - Gateway `.env`: `NAGIOS_BASE_URL`, `NAGIOS_USER`, `NAGIOS_PASS`, `GATEWAY_INTERNAL_SECRET`
   - Backend `.env`: `GATEWAY_BASE_URL`, `GATEWAY_INTERNAL_SECRET`

3. **Run Migrations:**
   ```bash
   cd backend
   php artisan migrate
   ```

## Verification Steps

### Step 1: Verify Gateway Internal Routes

```bash
# List all registered routes
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
```

### Step 2: Verify Direct Nagios API Access

```bash
# Test Nagios service count
curl -s -u nagiosadmin:nagios \
  "http://127.0.0.1:8080/nagios/cgi-bin/statusjson.cgi?query=servicecount" | jq

# Test Nagios service list
curl -s -u nagiosadmin:nagios \
  "http://127.0.0.1:8080/nagios/cgi-bin/statusjson.cgi?query=servicelist" | jq '.data.servicelist | keys | length'

# Expected: Returns JSON with service counts or service list
```

### Step 3: Verify Gateway Nagios Adapter Returns Real Data

```bash
# Test gateway summary endpoint (should return real totals, not just fetched_at)
curl -s -H "x-internal-secret: dev-secret-change-in-production" \
  http://127.0.0.1:4000/internal/engines/nagios/summary | jq

# Expected output:
# {
#   "success": true,
#   "data": {
#     "totals": {
#       "ok": <number>,
#       "warning": <number>,
#       "critical": <number>,
#       "unknown": <number>,
#       "pending": <number>
#     },
#     "fetched_at": "2026-01-26T..."
#   }
# }

# Test gateway services endpoint (should return real service rows)
curl -s -H "x-internal-secret: dev-secret-change-in-production" \
  http://127.0.0.1:4000/internal/engines/nagios/services | jq '.data | length'

# Expected: Returns array of service objects with:
# - host_name
# - service_name
# - state
# - last_check_at
# - duration_sec
# - attempt
# - output
# - perfdata

# Test gateway services with host_prefix filtering
curl -s -H "x-internal-secret: dev-secret-change-in-production" \
  "http://127.0.0.1:4000/internal/engines/nagios/services?host_prefix=ws84-" | jq '.data | length'

# Expected: Returns only services with host_name starting with "ws84-"
```

### Step 4: Verify Docker Compose Mount

```bash
# Check bind mount exists in docker-compose
grep -A 5 "volumes:" docker-compose.nagios.yml | grep "portshield"

# Expected: Should show: - ./nagios/config:/opt/nagios/etc/objects/portshield

# Verify directory structure
ls -la nagios/config/
ls -la nagios/config/workspaces/

# Expected:
# nagios/config/portshield.cfg exists
# nagios/config/workspaces/ directory exists
```

### Step 5: Verify PortShield Config Loading

```bash
# Check portshield.cfg content
cat nagios/config/portshield.cfg

# Expected:
# cfg_dir=/opt/nagios/etc/objects/portshield/workspaces

# Check if Nagios base config includes portshield.cfg
docker exec nagios-core grep -n "portshield" /opt/nagios/etc/nagios.cfg

# Expected: Should show line with: cfg_file=/opt/nagios/etc/objects/portshield/portshield.cfg
# (If not present, gateway will auto-add it on next config write)

# Verify workspace configs are accessible in container
docker exec nagios-core ls -la /opt/nagios/etc/objects/portshield/workspaces/

# Expected: Should list workspace .cfg files (e.g., 84.cfg)
```

### Step 6: Create Targets and Publish

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

# Verify config file was created
cat nagios/config/workspaces/84.cfg

# Expected: Should show Nagios config with host_name "ws84-web-server-01"
```

### Step 7: Verify Nagios Reload

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

# Verify Nagios sees the new hosts
curl -s -u nagiosadmin:nagios \
  "http://127.0.0.1:8080/nagios/cgi-bin/statusjson.cgi?query=hostlist" | jq '.data.hostlist[] | select(.name | startswith("ws84-"))'

# Expected: Should list hosts with names starting with "ws84-"
```

### Step 8: Verify Backend Poller with Gateway Filtering

```bash
cd backend

# Poll specific workspace (should use host_prefix parameter)
php artisan observe:poll --workspace_id=84

# Expected output:
# Polling workspace 84 (Workspace Name)...
# Successfully polled 84: X services

# Verify data was stored (only workspace-scoped services)
php artisan tinker
>>> \App\Models\ObserveService::where('workspace_id', 84)->count()
>>> \App\Models\ObserveService::where('workspace_id', 84)->pluck('host_name')->unique()
# All host names should start with "ws84-"

# Verify no cross-workspace leakage
>>> \App\Models\ObserveService::where('workspace_id', 84)->where('host_name', 'not like', 'ws84-%')->count()
# Expected: 0
```

### Step 9: Verify API Returns Only Workspace-Scoped Data

```bash
# Get services via API
curl -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/workspaces/84/observe/services?limit=50" | jq '.data.items[].host'

# Expected: All host names should start with "ws84-"

# Verify summary
curl -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/workspaces/84/observe/summary" | jq

# Expected: Returns totals for workspace 84 only
```

### Step 10: Verify Workspace Scoping End-to-End

```bash
# Create targets for a different workspace (e.g., 85)
curl -X PUT http://127.0.0.1:8000/api/workspaces/85/observe/targets \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "hosts": [
      {
        "name": "db-server-01",
        "address": "192.168.1.20",
        "check_command": "check-host-alive",
        "enabled": true,
        "services": [
          {
            "name": "MySQL",
            "check_command": "check_ping",
            "enabled": true
          }
        ]
      }
    ]
  }'

# Poll both workspaces
php artisan observe:poll --workspace_id=84
php artisan observe:poll --workspace_id=85

# Verify isolation
php artisan tinker
>>> \App\Models\ObserveService::where('workspace_id', 84)->pluck('host_name')->unique()
# Should only show ws84-* hosts

>>> \App\Models\ObserveService::where('workspace_id', 85)->pluck('host_name')->unique()
# Should only show ws85-* hosts

# Verify API isolation
curl -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/workspaces/84/observe/services" | jq '.data.items[].host' | sort -u
# Should only show ws84-* hosts

curl -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/workspaces/85/observe/services" | jq '.data.items[].host' | sort -u
# Should only show ws85-* hosts
```

## Build Verification

### Gateway
```bash
cd gateway
npm install  # If needed
npm run build

# Expected: No errors, TypeScript compiles successfully
```

### Backend
```bash
cd backend
php artisan test --filter=ObserveTest

# Expected: All tests pass
```

### Frontend
```bash
cd frontend
npm run build

# Expected: Build completes successfully
```

## Summary Checklist

- [ ] Gateway internal routes accessible (`/_debug/routes`)
- [ ] Direct Nagios API accessible (servicecount, servicelist)
- [ ] Gateway summary endpoint returns real totals (not just fetched_at)
- [ ] Gateway services endpoint returns real service rows with all fields
- [ ] Gateway services endpoint supports `host_prefix` filtering
- [ ] Docker compose bind mount configured correctly
- [ ] `portshield.cfg` exists and includes workspace directory
- [ ] Nagios base config includes `portshield.cfg` (or auto-added)
- [ ] Workspace configs created in `nagios/config/workspaces/{id}.cfg`
- [ ] Reload endpoint returns `validated: true, reloaded: true`
- [ ] Nagios sees workspace-prefixed hosts (`ws{id}-...`)
- [ ] Backend poller uses `host_prefix` parameter
- [ ] Backend poller stores only workspace-scoped services
- [ ] API returns only workspace-scoped services
- [ ] No cross-workspace data leakage
- [ ] All builds pass (gateway, backend, frontend)

## Troubleshooting

### Gateway Returns Empty Data

1. **Check Nagios is running:**
   ```bash
   docker ps | grep nagios-core
   curl -u nagiosadmin:nagios http://127.0.0.1:8080/nagios/
   ```

2. **Check environment variables:**
   ```bash
   # In gateway directory
   cat .env | grep NAGIOS
   ```

3. **Check gateway logs for errors**

### Backend Poller Returns 404

1. **Verify gateway routes:**
   ```bash
   curl -H "x-internal-secret: dev-secret-change-in-production" \
     http://127.0.0.1:4000/internal/engines/_debug/routes
   ```

2. **Check secret matches:**
   - Gateway: `GATEWAY_INTERNAL_SECRET`
   - Backend: `GATEWAY_INTERNAL_SECRET` (should match)

### Nagios Doesn't See Workspace Hosts

1. **Check config file exists:**
   ```bash
   cat nagios/config/workspaces/84.cfg
   ```

2. **Check Nagios includes portshield.cfg:**
   ```bash
   docker exec nagios-core grep "portshield" /opt/nagios/etc/nagios.cfg
   ```

3. **Validate and reload:**
   ```bash
   docker exec nagios-core /usr/local/nagios/bin/nagios -v /opt/nagios/etc/nagios.cfg
   curl -X POST http://127.0.0.1:4000/internal/engines/nagios/reload \
     -H "x-internal-secret: dev-secret-change-in-production"
   ```

### Cross-Workspace Data Leakage

1. **Verify host names are prefixed:**
   ```bash
   cat nagios/config/workspaces/84.cfg | grep host_name
   # Should show: host_name ws84-...
   ```

2. **Check poller uses host_prefix:**
   ```bash
   # Check backend poller code uses host_prefix parameter
   grep -A 5 "host_prefix" backend/app/Console/Commands/PollObserveData.php
   ```

3. **Verify API filters:**
   ```bash
   # Check API controller filters by prefix
   grep -A 3 "workspacePrefix" backend/app/Http/Controllers/ObserveController.php
   ```
