# ShieldObserve Engine Architecture

## Overview

**ShieldObserve** is the monitoring module and **observe engine** for Quenyx. It provides all monitoring features (host/service checks, plugins, scheduling, services UI) and is designed as a **microservice**: it must be accessed **only via the gateway**. No other module or service should call the observe engine directly (e.g. by hitting the backend URL); they must go through the gateway.

## Observe Engine (no Nagios required)

The **observe engine** is the in-platform monitoring core:

- **Built-in checks:** HTTP, TCP port, Ping (run natively in PHP).
- **Plugins:** Custom scripts (PHP, Perl, shell) that receive variables from the engine and return status (Nagios-style exit codes and output).
- **Scheduling:** Auto-checks run on an interval per service (`observe:run-checks`), so the Services UI stays up to date without any external daemon.
- **Storage:** Results are stored in the app database (`observe_services`, `observe_meta`, etc.).

Nagios is **optional**. When you use the native engine only, you do not need the Nagios daemon or the gateway’s Nagios routes. You can stop Nagios and rely on `observe:run-checks` and the Services/Targets UI.

## ShieldObserve as a Microservice

- **Boundary:** The observe engine (APIs + scheduler + storage) is the ShieldObserve microservice. Today it is implemented inside the main backend (Laravel); it can later be split into a separate deployable service.
- **Access rule:** Any consumer (frontend, other modules, or other services) must call the **gateway** to use observe. The gateway is the single entry point for observe APIs.
  - **Correct:** `GET https://gateway/api/workspaces/84/observe/services` (browser or server calls gateway).
  - **Wrong:** Calling the backend directly for observe, e.g. `GET https://backend/api/workspaces/84/observe/services` from another module.
- **Gateway routing:** Observe routes are under `/api/workspaces/{id}/observe/*` and `/api/projects/{id}/observe/*`. The gateway proxies these to the service that hosts the observe engine (by default the main backend; optionally a dedicated observe service via `OBSERVE_ENGINE_URL`).

## Auto-Check (Scheduler)

- The observe engine runs **automatic checks** via the Laravel scheduler.
- Command: `observe:run-checks` (runs native checks and plugins for all due services).
- Schedule: every minute (see `app/Console/Kernel.php`). Ensure the cron entry for `php artisan schedule:run` is active so auto-check runs.
- No Nagios process is required for auto-check when using the native engine.

## API Surface (via Gateway)

All observe APIs are exposed through the gateway. Examples (with gateway as origin):

| Purpose | Method | Path (via gateway) |
|--------|--------|---------------------|
| Summary / totals | GET | `/api/workspaces/{id}/observe/summary` |
| Service list | GET | `/api/workspaces/{id}/observe/services` |
| Service definitions | GET | `/api/workspaces/{id}/observe/service-definitions` |
| Monitored targets | GET/PUT | `/api/workspaces/{id}/observe/targets` |
| Alerts, reports, etc. | GET | `/api/workspaces/{id}/observe/...` |

The frontend and any other client must use the **gateway base URL** and these paths; they must not bypass the gateway to call the backend for observe.

## Optional: Dedicated Observe Service

To run the observe engine as a **separate service** (e.g. another Laravel app or a different stack):

1. Deploy the observe service and expose its API (e.g. same paths under `/api/workspaces/{id}/observe/*`).
2. Set **`OBSERVE_ENGINE_URL`** in the gateway environment to that service base URL (e.g. `http://observe-service:8000`).
3. The gateway will proxy **observe** requests to `OBSERVE_ENGINE_URL` and all other `/api` traffic to `BACKEND_BASE_URL`.

Until `OBSERVE_ENGINE_URL` is set, the gateway continues to send all `/api` (including observe) to the main backend.

## Summary

- **Observe engine** = native checks + plugins + scheduling; no Nagios required.
- **ShieldObserve** = observe engine as a microservice; **all access via gateway**.
- **Auto-check** = `observe:run-checks` on the scheduler (every minute).
- **Other modules** that need observe data or actions must call the **gateway**, not the backend (or observe service) directly.
