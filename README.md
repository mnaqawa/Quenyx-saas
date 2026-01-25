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
└── docs/             # Documentation
```

## Features

- **Multi-tenant Projects**: Project-scoped resources with ownership authorization
- **Module-based Entitlements**: Plan-based module access with per-project overrides
- **API Gateway**: Request proxying with entitlement enforcement middleware
- **Audit Logging**: Track module override changes and access modifications
- **Internationalization**: Arabic and English language support
- **Mobile Responsive**: Optimized for all device types

## Quick Start (Development)

### Prerequisites

- PHP 8.1+ with Composer
- Node.js 18+ with npm
- MySQL 8.0+
- Nginx (for production)

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

2. Install dependencies:
   ```bash
   npm install
   ```

3. Configure environment (optional):
   ```bash
   cp .env.example .env
   ```
   
   Set `VITE_API_BASE_URL` if backend is not on `http://localhost:8000`

4. Start the development server:
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

## Deployment

### Single Node Deployment (Development)

**Requirements:**
- Single server with PHP, Node.js, MySQL, and Nginx

**Steps:**

1. **Clone Repository:**
   ```bash
   git clone <repository-url>
   cd portshield-saas
   ```

2. **Backend Setup:**
   ```bash
   cd backend
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   # Edit .env with production database credentials
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

3. **Frontend Build:**
   ```bash
   cd ../frontend
   npm ci
   npm run build
   # Output in frontend/dist/
   ```

4. **Gateway Setup:**
   ```bash
   cd ../gateway
   npm ci
   npm run build
   ```

5. **Nginx Configuration:**
   ```nginx
   server {
       listen 80;
       server_name dev.portshield.net;
       root /var/www/portshield/portshield-saas/frontend/dist;
       index index.html;

       # Frontend static files
       location / {
           try_files $uri $uri/ /index.html;
       }

       # API requests go through gateway
       location /api/ {
           proxy_pass http://127.0.0.1:4000;
           proxy_http_version 1.1;
           proxy_set_header Upgrade $http_upgrade;
           proxy_set_header Connection 'upgrade';
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto $scheme;
           proxy_set_header Authorization $http_authorization;
           proxy_cache_bypass $http_upgrade;
           
           # Timeouts
           proxy_connect_timeout 60s;
           proxy_send_timeout 60s;
           proxy_read_timeout 60s;
       }
   }
   ```

6. **Systemd Services:**

   **Backend (Laravel):**
   ```ini
   # /etc/systemd/system/portshield-backend.service
   [Unit]
   Description=PortShield Backend API
   After=network.target

   [Service]
   Type=simple
   User=www-data
   WorkingDirectory=/var/www/portshield/portshield-saas/backend
   ExecStart=/usr/bin/php artisan serve --host=127.0.0.1 --port=8000
   Restart=always

   [Install]
   WantedBy=multi-user.target
   ```

   **Gateway:**
   ```ini
   # /etc/systemd/system/portshield-gateway.service
   [Unit]
   Description=PortShield API Gateway
   After=network.target

   [Service]
   Type=simple
   User=www-data
   WorkingDirectory=/var/www/portshield/portshield-saas/gateway
   Environment="GATEWAY_PORT=4000"
   Environment="BACKEND_BASE_URL=http://127.0.0.1:8000"
   Environment="ENTITLEMENTS_CACHE_TTL_MS=30000"
   ExecStart=/usr/bin/node dist/server.js
   Restart=always

   [Install]
   WantedBy=multi-user.target
   ```

7. **Start Services:**
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable portshield-backend
   sudo systemctl enable portshield-gateway
   sudo systemctl start portshield-backend
   sudo systemctl start portshield-gateway
   sudo systemctl reload nginx
   ```

### Multi-Node Deployment (Production)

**Architecture:**
- **Load Balancer**: Nginx (terminates SSL, routes traffic)
- **Frontend Nodes**: Serve static files (can be CDN)
- **Gateway Nodes**: API gateway instances (load balanced)
- **Backend Nodes**: Laravel API instances (load balanced)
- **Database**: MySQL (master-slave or cluster)

**Node Roles:**

1. **Load Balancer Node:**
   - Nginx with SSL termination
   - Routes `/api/*` to gateway nodes
   - Routes `/` to frontend nodes or CDN

2. **Gateway Nodes (2+):**
   - Run gateway service
   - Stateless (except in-memory cache)
   - Health check endpoint: `/health`

3. **Backend Nodes (2+):**
   - Run Laravel API
   - Shared database
   - Session storage (Redis/DB)

**Steps:**

1. **Load Balancer Nginx Config:**
   ```nginx
   upstream gateway {
       least_conn;
       server gateway1.internal:4000;
       server gateway2.internal:4000;
   }

   upstream backend {
       least_conn;
       server backend1.internal:8000;
       server backend2.internal:8000;
   }

   server {
       listen 443 ssl http2;
       server_name portshield.net;
       
       ssl_certificate /path/to/cert.pem;
       ssl_certificate_key /path/to/key.pem;

       # Frontend (or CDN)
       location / {
           proxy_pass http://frontend-cdn;
       }

       # API through gateway
       location /api/ {
           proxy_pass http://gateway;
           proxy_http_version 1.1;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto $scheme;
           proxy_set_header Authorization $http_authorization;
       }
   }
   ```

2. **Gateway Node Setup:**
   ```bash
   cd gateway
   npm ci
   npm run build
   # Set BACKEND_BASE_URL to load balancer or backend cluster
   ```

3. **Backend Node Setup:**
   ```bash
   cd backend
   composer install --no-dev --optimize-autoloader
   # Configure .env with shared database
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   ```

4. **Health Checks:**
   - Gateway: `GET /health` → `{"status":"ok","service":"gateway"}`
   - Backend: `GET /api/health` → Check Laravel health endpoint

5. **Monitoring:**
   - Monitor gateway nodes (port 4000)
   - Monitor backend nodes (port 8000)
   - Monitor database connections
   - Set up alerts for 502/503 errors

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
