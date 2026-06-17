# Quenyx Backend API

**PROPRIETARY SOFTWARE - Copyright (c) 2026 Quenyx CO. All rights reserved.**

This software is the proprietary property of Quenyx CO. Unauthorized use, copying, modification, or distribution is strictly prohibited.

Laravel API-only backend for Quenyx vOPS HUB.

## Requirements

- PHP >= 8.1
- Composer
- MySQL >= 5.7

## Local Setup

1. **Install dependencies:**
   ```bash
   composer install
   ```

2. **Configure environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Update `.env` with your database credentials:**
   ```
   DB_DATABASE=quenyx_dev
   DB_USERNAME=quenyx
   DB_PASSWORD=your_password
   ```

4. **Run migrations:**
   ```bash
   php artisan migrate
   ```

5. **Start the development server:**
   ```bash
   php artisan serve
   ```

The API will be available at `http://localhost:8000`

## Testing

```bash
php artisan test
```

## Project Structure

- `app/Http/Controllers/` - Thin controllers that delegate to services
- `app/Services/` - Business logic layer
- `app/Repositories/` - Data access layer
- `app/DTO/` - Data Transfer Objects
- `routes/api.php` - API routes

## API Endpoints

- `GET /api/health` - Health check endpoint
