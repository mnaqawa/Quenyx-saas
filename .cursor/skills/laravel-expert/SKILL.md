---
name: laravel-expert
description: Expert guidance for Laravel, MySQL, migrations, validation, DTOs/resources, queues, schedulers, transactions, JSON casting, Eloquent performance, and API design. Emphasizes correctness, idempotency, deterministic data mapping, and observability. Use when working with Laravel backend code, migrations, API contracts, jobs, or database design.
---

# Laravel Expert

Apply this skill when implementing or reviewing Laravel backend code. Prioritize **correctness**, **idempotency**, **deterministic data mapping**, and **observability**.

---

## Principles

- **Correctness**: Validate at the boundary; fail fast; never hide backend errors in the frontend.
- **Idempotency**: Safe to retry; design jobs and endpoints so duplicate calls produce the same outcome.
- **Deterministic mapping**: Use stable identifiers (IDs, keys) for joins and sync; avoid relying on display names or order.
- **Observability**: Log critical transformations (request → normalize → persist → publish); use structured context (IDs, action).

---

## Migrations

- **Reversible**: Implement `down()` so migrations can be rolled back; avoid destructive `down()` without explicit backup/safety.
- **Indexes**: Add indexes for foreign keys, unique constraints, and columns used in `WHERE`/`ORDER BY`; avoid indexing every column.
- **Naming**: Use `create_*_table`, `add_*_to_*_table`, descriptive index names (`table_column_index`).
- **Columns**: Prefer `unsignedBigInteger` for FKs; `decimal` for money; `timestamp`/`timestamps()` for times; explicit lengths only when required.
- **No business logic**: Migrations only change schema; use seeders or jobs for data changes.

---

## Validation

- Prefer **Form Requests** for HTTP input; keep controllers thin.
- Rules: use `required`, `exists:table,column`, `unique:table,column,except,id`, `array`, `*.field` for nested; avoid duplicate rules in multiple places.
- **Deterministic mapping**: Validate external IDs (e.g. `exists:tenants,id`) before using them in relations; return 422 with clear messages.
- Custom rules for domain invariants; use `Rule` objects for complex or reusable logic.
- Never trust client-provided IDs for authorization; resolve the model and authorize in the controller/policy.

---

## DTOs and API Resources

- **DTOs**: Use for internal boundaries (e.g. queue payloads, service inputs). Immutable where possible; explicit properties; no Eloquent models inside.
- **API Resources**: Use for HTTP responses. Map model → array deterministically; same input always yields same output. Use `when()`/`merge()` for conditional fields; avoid N+1 by eager loading before `Resource::collection()`.
- **Mapping**: Prefer IDs and stable keys in payloads; document the contract (field names, types) so consumers can rely on it.

---

## Queues and Jobs

- **Idempotency**: Design jobs so running twice (e.g. retry after failure) does not duplicate side effects. Use unique keys, `firstOrCreate`, or conditional updates.
- **Payloads**: Pass IDs and minimal data; load models inside the job. Avoid serializing large objects or closures.
- **Failure handling**: Use `tries`, `backoff`, `retryUntil`; log failures with job name and identifiers; use failed job table and alerts.
- **Queues**: Use named queues for priority (e.g. `high`, `default`, `low`); dispatch to the appropriate queue.

---

## Schedulers

- **Idempotency**: Scheduled tasks may run in overlapping or duplicate environments; design so multiple runs do not corrupt data.
- **Cron expression**: Use Laravel’s scheduler in `app/Console/Kernel.php`; prefer `hourly()`, `daily()`, etc., or explicit cron strings.
- **One-off work**: Put logic in jobs and schedule job dispatch; keep the schedule definition small and observable (log when tasks are scheduled).

---

## Transactions

- Wrap multi-step writes in `DB::transaction()` so either all steps commit or none do.
- **Order**: Lock or update in a consistent order to reduce deadlock risk (e.g. always parent before child, or by ID).
- **Read-your-writes**: If the next step depends on a previous write, stay inside the same transaction; avoid long-running transactions.

---

## JSON Casting and Storage

- Use `$casts = ['column' => 'array']` or `'json'` for JSON columns; use `AsArrayObject`/`AsCollection` when mutating nested keys.
- **Indexing**: For MySQL, use generated columns or application-level keys if you need to query inside JSON; avoid unindexed JSON filters on large tables.
- **Schema**: Prefer normalized columns for frequently queried or joined data; use JSON for flexible/variable structure.

---

## Eloquent and Performance

- **N+1**: Eager load with `with()` for relations used in loops; use `withCount()`/`withExists()` when only counts are needed.
- **Select only what you need**: `select()` specific columns when full models are not required; avoid `SELECT *` on wide tables.
- **Chunking**: Use `chunk()`, `chunkById()`, or `lazyById()` for large datasets; `chunkById` is safer when data changes during iteration.
- **Unnecessary load**: Don’t load a full model when `update()`, `increment()`, or `delete()` with a query is enough.

---

## API Design

- **REST**: Use standard HTTP methods and status codes (200, 201, 204, 400, 401, 403, 404, 422, 500); return consistent JSON shape.
- **Versioning**: Prefer URL or header versioning and stick to it; support at least one previous version during changes.
- **Contracts**: Document request/response shapes; use Resources and validation so responses are deterministic and stable.
- **Errors**: Return structured error payloads (e.g. `message`, `errors` for validation); avoid leaking stack traces in production.

---

## Checklist for Changes

- [ ] Migrations reversible and indexed appropriately
- [ ] Validation at boundary; 422 with clear messages for invalid input
- [ ] Payloads and responses use stable identifiers and deterministic mapping
- [ ] Jobs and scheduled tasks are idempotent where possible
- [ ] Multi-step writes wrapped in transactions; consistent lock/update order
- [ ] No N+1; eager load or select only needed columns
- [ ] Critical flows have structured logs (IDs, action, outcome)
- [ ] API responses and errors follow project contract and status codes
