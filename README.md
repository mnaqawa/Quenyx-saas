# PortShield SaaS

Monorepo for PortShield SaaS platform.

## Stack

- **Frontend**: React + TypeScript (Vite)
- **Backend**: Laravel API-only
- **Database**: MySQL

## Project Structure

```
portshield-saas/
├── backend/          # Laravel API-only backend
├── frontend/         # React + TypeScript + Vite frontend
└── docs/             # Documentation
```

## Quick Start

### Backend

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

4. Update `.env` with your database credentials

5. Run migrations:
   ```bash
   php artisan migrate
   ```

6. Start the server:
   ```bash
   php artisan serve
   ```

Backend will be available at `http://localhost:8000`

### Frontend

1. Navigate to frontend directory:
   ```bash
   cd frontend
   ```

2. Install dependencies:
   ```bash
   npm install
   ```

3. Configure environment:
   ```bash
   cp .env.example .env
   ```

4. Start the development server:
   ```bash
   npm run dev
   ```

Frontend will be available at `http://localhost:5173`

## Architecture

### Backend (Laravel)

- **Controllers**: Thin controllers that delegate to services
- **Services**: Business logic layer
- **Repositories**: Data access layer
- **DTOs**: Data Transfer Objects for type-safe data handling

### Frontend (React)

- **Components**: Reusable UI components (no business logic)
- **Pages**: Page-level components
- **Services**: API client and service layer (business logic)
- **Hooks**: Custom React hooks for reusable logic
- **Types**: TypeScript type definitions

## Development Rules

- No auth/user management unless explicitly requested
- Controllers thin, logic in Services, DB in Repositories
- Strict TypeScript, no logic in React components
- Every endpoint validated and returns consistent JSON errors
- Add basic tests for each feature
- Small PRs only (one issue = one slice)

## API Endpoints

- `GET /api/health` - Health check endpoint
