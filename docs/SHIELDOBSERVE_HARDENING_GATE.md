# ShieldObserve Hardening Gate (TPM Acceptance)

> **⚠️ SUPERSEDED / LEGACY (pre‑v1.0.0).** The publish/poll/reload flows and `NAGIOS_*` gates described
> here belonged to the former **Nagios‑based** runtime, which has been **removed**. QynSight monitoring
> is now **native** (`observe:run-checks`); `/internal/engines/nagios*` returns `410 Gone`. Retained for
> historical context only — see **[`docs/OBSERVE_RUNBOOK.md`](./OBSERVE_RUNBOOK.md)** for current procedures.

This document describes the release gates implemented for ShieldObserve publish and poll flows, and the manual test plan for failure simulations.

## A) Atomic Publish

- **Write to temp path:** Config is written to `{workspaceId}.cfg.new` on the host (under `NAGIOS_CONFIG_DIR/workspaces`).
- **Validate then swap:** Inside the Nagios container, the gateway:
  1. Backs up current `{workspaceId}.cfg` to `{workspaceId}.cfg.bak`
  2. Moves `{workspaceId}.cfg.new` to `{workspaceId}.cfg`
  3. Runs `nagios -v` (validation only; no reload)
  4. If validation fails: restores `{workspaceId}.cfg` from `.bak` and returns 400 (config not activated).
- **Reload step:** Backend then calls `POST /internal/engines/nagios/reload`. Gateway validates, reloads (HUP or restart), verifies; on failure restores all `.cfg.bak` files (rollback).
- **Deterministic and idempotent:** Same inputs produce same config; repeated publish is safe.
- **Concurrency:** Backend uses a distributed lock (`Cache::lock('nagios_publish', 60)`) so only one publish runs at a time across instances.

## B) Validation

- **Validate before activation:** `nagios -v` is run after the atomic swap and before any reload. If invalid, config is rolled back and reload is not attempted.
- **Fail cleanly:** On validation failure the gateway returns 400 with `validation_errors`; backend does not call reload.
- **check_command:** Validation catches unknown `check_command` and duplicate object errors (via `nagios -v` output).

## C) Reload Verification

- **Not blind:** After sending HUP or restart, the gateway waits `NAGIOS_RELOAD_VERIFY_SLEEP_MS` (default 2s) then runs `nagios -v` again to confirm config is still valid.
- **Timeout and retry:** Reload uses `NAGIOS_RELOAD_TIMEOUT_MS` (default 15s) and `NAGIOS_RELOAD_RETRIES` (default 2). On timeout or verification failure, reload is treated as failure and rollback runs.

## D) Automatic Rollback

- **On validation failure (write step):** Single-workspace rollback: `.cfg` is restored from `.bak`, `.new` is left for inspection.
- **On reload/verification failure:** Gateway runs `rollbackAllBackups()`: every `*.cfg.bak` in the workspaces dir is restored to `.cfg`, then `nagios -v` is run to confirm. Response includes `message` indicating rollback.

## E) Degraded Mode

- **Gateway/Nagios/statusjson.cgi unreachable:** When poll fails (e.g. gateway timeout, Nagios down), the backend:
  - Updates all `observe_services` for that workspace to `state = 'unreachable'`, `output = 'Engine unreachable'`.
  - Updates `observe_meta` with `error` and `last_poll_at`.
- **API response:** Services API returns `engine_unreachable: true`, `source_timestamp` (last_poll_at), and `stale: true` when data is older than `observe.stale_threshold_seconds` (default 300). State code `9` is used for UNREACHABLE in `state_code`.
- **UI:** Frontend should show "Engine unreachable" and a stale indicator when `engine_unreachable` or `stale` is true.

## F) HA-Friendly Gateway

- **No local-only assumptions:** Config path is env-driven (`NAGIOS_CONFIG_DIR`, `NAGIOS_CONTAINER_WORKSPACES_DIR`). Reload uses Docker exec with configurable container name.
- **Distributed lock:** Backend uses Laravel `Cache::lock('nagios_publish', $seconds)`. With Redis or database cache driver, multiple backend instances cannot publish concurrently; one blocks until the other releases.
- **Config distribution:**
  - **Single-node:** One gateway, one Nagios. Lock prevents concurrent publish from the same or multiple workers. Config dir is local or a single mount.
  - **Multi-node:** Multiple gateway instances can run; each writes to a **shared** config store (e.g. NFS or shared volume) that is mounted into the Nagios container(s). Lock is in Redis/DB so only one backend instance runs publish at a time. Reload is sent to the Nagios instance that mounts that config (same node or designated node). For multiple Nagios instances, document which node runs reload and how config is synced (e.g. shared storage + single reload target).

## G) Platform Observability

- **Publish audit:** Every publish (success or failure) is logged via `Log::info('observe_nagios_publish', [...])` and, when `audit_logs` table exists, `AuditLog::create` with `action = observe_nagios_publish`, `metadata = { result, error, validation_errors }`, plus `user_id` and `project_id` when available.
- **Gateway health/readiness:**
  - `GET /health` — liveness; returns 200 and `{ status: 'ok', service: 'gateway' }`.
  - `GET /ready` — readiness; runs:
    - **nagios_reachable:** Docker access and container check.
    - **config_dir_writable:** Gateway can create and write a test file under `NAGIOS_CONFIG_DIR/workspaces`.
    - **statusjson_cgi:** Gateway can reach Nagios `statusjson.cgi` (e.g. summary).
  - Returns 200 with `{ status: 'ready', checks }` when all pass; 503 with `{ status: 'not_ready', checks }` when any fail.

---

## Manual Test Plan (Failure Simulations)

### 1. Kill gateway mid-write

- **Setup:** Start gateway, start a PUT to `/internal/engines/nagios/config` with a large body.
- **Action:** Kill the gateway process (e.g. SIGKILL) before the request completes.
- **Expected:** Host may have `{id}.cfg.new` only; no swap. No `.bak` yet. After restart, either a subsequent publish overwrites `.new` and completes, or operator can remove `.new`. Nagios continues to use previous `.cfg` if any.

### 2. Introduce invalid config

- **Setup:** Send PUT with config that references a non-existent `check_command` or has a duplicate host name.
- **Expected:** Gateway writes `.new`, swaps, runs `nagios -v`; validation fails. Gateway rolls back (restores `.cfg` from `.bak`), returns 400 with `validation_errors`. Backend does not call reload. No reload attempt.

### 3. Force reload failure

- **Setup:** Stop the Nagios container (or make `kill -HUP` fail).
- **Action:** Publish valid config (PUT 200), then backend calls POST reload.
- **Expected:** Gateway validates, attempts HUP/restart; reload or verification fails. Gateway runs `rollbackAllBackups()`, restores previous `.cfg` from `.bak`, returns 5xx with message indicating rollback. Backend records `last_publish_success = false` and error.

### 4. Stop Nagios / break statusjson.cgi

- **Setup:** Stop Nagios container or change `NAGIOS_BASE_URL` to an unreachable host.
- **Action:** Run poll (`php artisan observe:poll --workspace_id=1`) or trigger from UI.
- **Expected:** Poll fails. Backend sets `observe_services.state = 'unreachable'`, `output = 'Engine unreachable'`, and `observe_meta.error`. Services API returns `engine_unreachable: true`, `stale`, and items with `state_code: 9`.

### 5. Concurrent publish attempts

- **Setup:** Two backend workers or two simultaneous requests that trigger publish (e.g. two users saving targets at once).
- **Expected:** One acquires `nagios_publish` lock and completes. The other blocks until lock timeout (or until first releases). If lock timeout, second fails with "Could not acquire publish lock". Only one publish runs at a time.

### 6. Readiness endpoint

- **Action:** `GET /ready` with Nagios up and config dir writable → 200 and `status: 'ready'`.
- **Action:** Stop Nagios or make config dir read-only → 503 and `status: 'not_ready'` with failing checks in response.

---

## Environment Variables (Gateway)

| Variable | Purpose |
|----------|---------|
| `NAGIOS_CONFIG_DIR` | Host path to config root (absolute recommended). |
| `NAGIOS_CONTAINER_WORKSPACES_DIR` | Container path to workspaces dir (default `/opt/nagios/etc/objects/quenyx/workspaces`). |
| `NAGIOS_RELOAD_TIMEOUT_MS` | Timeout for reload step (default 15000). |
| `NAGIOS_RELOAD_RETRIES` | Retries after reload/verify (default 2). |
| `NAGIOS_RELOAD_VERIFY_SLEEP_MS` | Sleep before re-validating after reload (default 2000). |

## Backend Config (observe.php)

| Key | Purpose |
|-----|---------|
| `stale_threshold_seconds` | Consider data stale if older than this (default 300). |
| `publish_lock_seconds` | Max time to hold publish lock (default 60). |
