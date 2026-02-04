# PortShield SaaS

**PROPRIETARY SOFTWARE - Copyright (c) 2026 PortShield CO. All rights reserved.**

This software is the proprietary property of PortShield CO. Unauthorized use, copying, modification, or distribution is strictly prohibited. Use of this software is permitted only with explicit written authorization from PortShield CO.

Monorepo for PortShield SaaS platform with API gateway, entitlement enforcement, and multi-tenant project management.

## Stack

- **Frontend**: React + TypeScript (Vite) + Tailwind CSS
- **Backend**: Laravel API-only (Sanctum auth)
- **Gateway**: Node.js + TypeScript + Express (entitlement enforcement)
- **Database**: MySQL
- **Web Server**: Nginx

## Project Structure

```
portshield-saas/
├── backend/          # Laravel API-only backend
├── frontend/         # React + TypeScript + Vite frontend
├── gateway/          # Node.js API gateway with entitlement enforcement
├── docs/             # Documentation
├── DEPLOYMENT.md      # Full deployment guide (single-node & multi-node)
└── README.md         # This file
```

## Features

- **Multi-tenant Projects**: Project-scoped resources with ownership authorization
- **Module-based Entitlements**: Plan-based module access with per-project overrides
- **API Gateway**: Request proxying with entitlement enforcement middleware
- **Audit Logging**: Track module override changes and access modifications
- **Internationalization**: Arabic and English language support
- **Mobile Responsive**: Optimized for all device types

## Getting started (platform users)

After deployment, use the platform as follows:

1. **Log in** with the seeded credentials (or your own after changing them).
2. **Select a workspace** (e.g. Production Env or Staging Env) from the **Workspace** dropdown in the top bar. All data is scoped to the selected workspace.
3. **Add hosts** in **Observe → Monitored Targets** (name and address). Without hosts, Real-time Monitoring and Dashboard show an empty state and ask you to add hosts first.
4. **Add services** for each host (e.g. CPU, disk, HTTP). Status and problems then appear on the Dashboard and in Real-time Monitoring.
5. **View Dashboard** for a health-at-a-glance line and ShieldObserve summary; **Real-time Monitoring** for server metrics and host/service status.
6. **Infrastructure Map** for topology, zones (DMZ, WebApp, DB, etc.), and export (PNG, PDF, SVG, JSON). **Integrations** for webhooks (alerts to Slack/Teams/email) and external topology.

In-app **Getting started** (sidebar) links to a short guide. For production deployment and real testing, see [DEPLOYMENT.md](DEPLOYMENT.md).

## Quick Start (Development)

### Prerequisites

- PHP 8.1+ with Composer
- Node.js 18+ LTS (or 20+) with npm
- MySQL 8.0+
- Nginx (for production deployment)

### Backend Setup

1. Navigate to backend directory:
   ```bash
   cd backend
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Configure environment:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. Update `.env` with your database credentials:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=portshield_dev
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

5. Run migrations and seeders:
   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   ```

6. Start the server:
   ```bash
   php artisan serve
   ```

Backend will be available at `http://localhost:8000`

**Default Login Credentials:**
- Email: `admin@portshield.test`
- Password: `Password123!`

### Frontend Setup

1. Navigate to frontend directory:
   ```bash
   cd frontend
   ```

2. Install dependencies (use `npm ci` for reproducible installs; lockfile is committed):
   ```bash
   npm install
   # or: npm ci
   ```

3. Configure environment (optional):
   ```bash
   cp .env.example .env
   ```
   
   Set `VITE_API_BASE_URL` if backend is not on `http://localhost:8000`

4. Build (optional, for production bundle):
   ```bash
   npm run build
   ```
   Output: `frontend/dist/`

5. Start the development server:
   ```bash
   npm run dev
   ```

Frontend will be available at `http://localhost:5173`

### Gateway Setup

1. Navigate to gateway directory:
   ```bash
   cd gateway
   ```

2. Install dependencies:
   ```bash
   npm install
   ```

3. Configure environment (optional):
   ```bash
   # Set in .env or environment
   GATEWAY_PORT=4000
   BACKEND_BASE_URL=http://127.0.0.1:8000
   ENTITLEMENTS_CACHE_TTL_MS=30000
   ```

4. Start the gateway:
   ```bash
   npm run dev
   ```

Gateway will be available at `http://localhost:4000`

## Architecture

### Backend (Laravel)

- **Controllers**: Thin controllers with policy-based authorization
- **Services**: Business logic layer (EntitlementService, ModuleService, etc.)
- **Repositories**: Data access layer
- **Policies**: Authorization rules (ProjectPolicy)
- **Middleware**: Authentication (Sanctum), CORS, etc.

**Key Endpoints:**
- `GET /api/modules` - Global module catalog
- `GET /api/projects/{project}/modules` - Project modules with access flags
- `GET /api/projects/{project}/entitlements` - Project entitlements (used by gateway)
- `PUT /api/projects/{project}/modules/{moduleKey}/override` - Module override management
- `GET /api/projects/{project}/audit-logs` - Audit log history

### Frontend (React)

- **Components**: Reusable UI components
- **Pages**: Page-level components (Dashboard, Projects, Subscriptions, etc.)
- **Services**: API client and service layer
- **Context**: Global state management (ProjectContext)
- **Types**: TypeScript type definitions

### Gateway (Node.js)

- **Proxy Middleware**: Forwards `/api/*` requests to backend
- **Entitlement Guard**: Enforces module access for protected routes
- **Caching**: In-memory entitlements cache (30s TTL)
- **Logging**: Request/response logging with token hashing

**Enforced Routes:**
- `/api/projects/:projectId/integrations*` → requires `shieldintegrations` module

**Allowed Routes (pass-through):**
- `/api/projects/:projectId/modules`
- `/api/projects/:projectId/modules/access`
- `/api/projects/:projectId/entitlements`
- All other `/api/*` routes

## Deployment and production

Full deployment instructions (single-node and multi-node), Nginx configs, systemd units, and ShieldObserve/Nagios gateway options are in **[DEPLOYMENT.md](DEPLOYMENT.md)**.

**Production checklist (see DEPLOYMENT.md):**
- Use `composer install --no-dev` and `npm ci` / `npm run build` for backend and frontend.
- Set `APP_ENV=production`, `APP_DEBUG=false`, and strong `APP_KEY`.
- Configure `.env` for production (DB, `APP_URL`, CORS, etc.).
- Run migrations and seeders; then restrict or remove seeder default credentials.
- Use HTTPS (Nginx SSL) and restrict CORS to your frontend origin.
- Run real tests (login, workspace switch, add host, view Observe) before go-live.

**Summary:**
- **Single-node**: Backend (Laravel), Gateway (Node), Frontend (static build), Nginx, MySQL. Use `npm ci` and `composer install --no-dev` for reproducible installs.
- **Multi-node**: Load balancer → Gateway pool and Backend pool; shared MySQL; optional CDN for frontend.
- **Gateway**: After code changes, rebuild (`npm run build`) and restart the gateway service so `dist/` is updated.

## Database Maintenance

### Cleanup Duplicate Modules

If duplicate modules appear in the database:

```bash
cd backend
php artisan modules:cleanup-duplicates
```

This command:
- Finds duplicate modules by `key`
- Keeps the one with lowest `id`
- Deletes duplicates and associated overrides

### Module Seeding

The `ModuleSeeder` is idempotent and only creates Shield modules:

```bash
php artisan db:seed --class=ModuleSeeder
```

## Development Rules

- **Controllers**: Thin, delegate to services
- **Services**: Business logic layer
- **Repositories**: Data access layer
- **Policies**: Authorization rules
- **Strict TypeScript**: No logic in React components
- **Consistent JSON**: All endpoints return `{ success: true/false, data/message }`
- **Module Catalog**: Only Shield modules (enforced at query level)

## API Response Format

All endpoints return consistent JSON:

**Success:**
```json
{
  "success": true,
  "data": { ... }
}
```

**Error:**
```json
{
  "success": false,
  "message": "Error description"
}
```

## Module System

### Module Catalog

The system maintains a catalog of Shield modules:
- ShieldCore (core module, always included)
- ShieldObserve
- ShieldInventory
- ShieldRespond
- ShieldSecure
- ShieldNotify
- ShieldVoice
- ShieldKnowledge
- ShieldAutomate
- ShieldBalance
- ShieldDesk
- ShieldIntegrations

### Entitlements

Modules are granted based on:
1. **Plan Features**: Each plan defines `features.modules` array
2. **Project Overrides**: Admins can force enable/disable modules per project
3. **Effective Access**: `allowed = (plan entitlement) + (overrides)`

### Gateway Enforcement

The gateway enforces module access for:
- `/api/projects/:projectId/integrations*` → requires `shieldintegrations`

All other routes pass through without enforcement.

## Security

- **Authentication**: Laravel Sanctum (Bearer tokens)
- **Authorization**: Policy-based (ProjectPolicy)
- **Token Hashing**: Gateway logs use hashed tokens (no secrets)
- **CORS**: Configured for frontend domain
- **SQL Injection**: Protected by Eloquent ORM
- **XSS**: React escapes by default

## Troubleshooting

### Modules Not Loading

1. Check backend logs: `tail -f storage/logs/laravel.log`
2. Verify database has modules: `php artisan tinker` → `Module::count()`
3. Run cleanup: `php artisan modules:cleanup-duplicates`
4. Check gateway logs for blocked requests

### Gateway 504 Errors

1. Verify backend is running: `curl http://127.0.0.1:8000/api/health`
2. Check gateway logs for connection errors
3. Verify `BACKEND_BASE_URL` is correct
4. Check Nginx proxy timeouts

### Duplicate Modules

1. Run cleanup command: `php artisan modules:cleanup-duplicates`
2. Verify unique index exists: `SHOW INDEXES FROM modules WHERE Key_name = 'modules_key_unique'`
3. Re-seed modules: `php artisan db:seed --class=ModuleSeeder`

## License

**PROPRIETARY SOFTWARE - Copyright (c) 2026 PortShield CO. All rights reserved.**

This software is the proprietary property of PortShield CO. Unauthorized use, copying, modification, or distribution is strictly prohibited. Use of this software is permitted only with explicit written authorization from PortShield CO.

See [LICENSE](LICENSE) for full terms and conditions.
