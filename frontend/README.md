# Quenyx Frontend

React + TypeScript + Vite frontend for Quenyx vOPS HUB.

## Requirements

- Node.js >= 18
- npm or yarn

## Local Setup

1. **Install dependencies:**
   ```bash
   npm install
   ```

2. **Configure environment:**
   ```bash
   cp .env.example .env
   ```

3. **Update `.env` if needed:**
   ```
   VITE_API_BASE_URL=http://localhost:8000
   ```

4. **Start the development server:**
   ```bash
   npm run dev
   ```

The app will be available at `http://localhost:5173`

## Build

```bash
npm run build
```

## Project Structure

- `src/components/` - Reusable UI components
- `src/pages/` - Page components
- `src/layouts/` - Layout components
- `src/services/` - API client and service layer
- `src/hooks/` - Custom React hooks
- `src/types/` - TypeScript type definitions

## Architecture

- **Strict TypeScript** - All code is fully typed
- **No logic in components** - Business logic lives in services/hooks
- **Typed API client** - Consistent error handling and response types
