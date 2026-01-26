# ShieldObserve Real Nagios Data Integration - Implementation Summary

## Files Created/Modified

### Gateway

**Created:**
- `gateway/src/engines/nagios.ts` - Nagios engine adapter with caching and concurrency control
- `gateway/src/engines/index.ts` - Engine routes registration with internal secret auth

**Modified:**
- `gateway/src/server.ts` - Added engine routes registration

### Backend

**Created:**
- `backend/database/migrations/2026_01_25_000020_create_observe_services_table.php` - Services table migration
- `backend/database/migrations/2026_01_25_000021_create_observe_meta_table.php` - Meta table migration
- `backend/app/Models/ObserveService.php` - ObserveService model
- `backend/app/Models/ObserveMeta.php` - ObserveMeta model
- `backend/app/Console/Commands/PollObserveData.php` - Polling command
- `backend/app/Http/Controllers/ObserveController.php` - Observe API controller
- `backend/tests/Feature/ObserveTest.php` - Feature tests

**Modified:**
- `backend/config/app.php` - Added gateway URL and secret config
- `backend/routes/api.php` - Added observe routes (workspace + project aliases)
- `backend/app/Console/Kernel.php` - Added scheduler for polling (dev only)
- `backend/app/Policies/ProjectPolicy.php` - Updated view() to allow members

### Frontend

**No changes required** - Frontend already uses `observeService.ts` which calls the correct endpoints. When `VITE_OBSERVE_USE_FIXTURES=false`, it automatically uses real API.

## Environment Variables

### Gateway (.env)
```bash
NAGIOS_BASE_URL=http://127.0.0.1:8080/nagios
NAGIOS_USER=nagiosadmin
NAGIOS_PASS=nagios
GATEWAY_INTERNAL_SECRET=dev-secret-change-in-production
```

### Backend (.env)
```bash
GATEWAY_BASE_URL=http://127.0.0.1:4000
GATEWAY_INTERNAL_SECRET=dev-secret-change-in-production
```

### Frontend (.env)
```bash
VITE_OBSERVE_USE_FIXTURES=false  # Set to false to use real API
```

## Setup Steps

1. **Run migrations:**
   ```bash
   cd backend
   php artisan migrate
   ```

2. **Start services:**
   ```bash
   # Terminal 1: Gateway
   cd gateway
   npm install  # If not already done
   npm run dev

   # Terminal 2: Backend
   cd backend
   php artisan serve

   # Terminal 3: Frontend (if needed)
   cd frontend
   npm run dev
   ```

3. **Poll data manually (or wait for scheduler):**
   ```bash
   cd backend
   php artisan observe:poll
   # Or for specific workspace:
   php artisan observe:poll --workspace_id=1
   ```

## Verification Commands

### 1. Test Gateway Internal Endpoints

```bash
# Get Nagios services (requires internal secret)
curl -H "x-internal-secret: dev-secret-change-in-production" \
  http://127.0.0.1:4000/internal/engines/nagios/services

# Get Nagios summary
curl -H "x-internal-secret: dev-secret-change-in-production" \
  http://127.0.0.1:4000/internal/engines/nagios/summary
```

### 2. Test Backend Polling Command

```bash
# Poll all workspaces
cd backend
php artisan observe:poll

# Poll specific workspace
php artisan observe:poll --workspace_id=1
```

### 3. Test Backend API Endpoints (after polling)

First, get an auth token:
```bash
# Login
TOKEN=$(curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}' \
  | jq -r '.data.token')

# Get summary
curl -H "Authorization: Bearer $TOKEN" \
  http://127.0.0.1:8000/api/workspaces/1/observe/summary

# Get services
curl -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/workspaces/1/observe/services?limit=10"

# Get services with filters
curl -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/workspaces/1/observe/services?problems=1&status=critical,warning&q=host1"

# Test project alias
curl -H "Authorization: Bearer $TOKEN" \
  http://127.0.0.1:8000/api/projects/1/observe/summary
```

### 4. Run Tests

```bash
cd backend
php artisan test --filter=ObserveTest
```

## Architecture

1. **Gateway** (`/internal/engines/nagios/*`):
   - Fetches data from Nagios API
   - Normalizes to stable structure
   - Caches for 30 seconds
   - Requires `x-internal-secret` header

2. **Backend Polling** (`observe:poll`):
   - Calls gateway internal endpoints
   - Upserts into `observe_services` table
   - Updates `observe_meta` with totals and timestamp
   - Scheduled every minute in dev

3. **Backend API** (`/api/workspaces/{id}/observe/*`):
   - Reads from normalized database tables
   - Supports filtering (q, status, problems, limit)
   - Sorts by severity
   - Requires workspace membership

4. **Frontend**:
   - Uses `observeService.ts` which calls backend API
   - Toggle via `VITE_OBSERVE_USE_FIXTURES` env var
   - No UI changes required

## Data Flow

```
Nagios → Gateway (cached) → Backend Poll Job → Database → Backend API → Frontend
```

## Notes

- Gateway caches Nagios responses for 30 seconds
- Backend polling uses concurrency limit of 5 for per-service detail fetches
- Database never deletes old data on poll failure (error stored in meta)
- Frontend automatically switches to real API when `VITE_OBSERVE_USE_FIXTURES=false`
- All endpoints support both `/api/workspaces/` and `/api/projects/` aliases
