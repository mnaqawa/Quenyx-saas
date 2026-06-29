# ShieldObserve Production Readiness

> **⚠️ SUPERSEDED / LEGACY (pre‑RC1.1).** This document describes the former **Nagios‑based**
> ShieldObserve runtime (gateway‑published configs, `observe:poll`, `NAGIOS_*` env, Nagios binary
> resolution). That runtime has been **removed**: QynSight monitoring is now **native**
> (`observe:run-checks`), and any `/internal/engines/nagios*` request returns `410 Gone`. This file is
> retained for historical/migration context only. For the current procedures, see
> **[`docs/OBSERVE_RUNBOOK.md`](./OBSERVE_RUNBOOK.md)**.

This document describes how to validate persistence and services data, and lists known limitations.

## 1. Validating Monitored Targets (Overrides) Persistence

### Database

- **Table:** `observe_targets_services`
- **Column:** `check_args` (JSON). Overrides are stored here as a JSON **object** (e.g. `{"port": 8080, "path": "/"}`). It must never be stored as a JSON array `[]` for overrides.

**Check after Save & Publish:**

```sql
SELECT id, name, check_command, check_args
FROM observe_targets_services
WHERE workspace_id = :workspace_id
ORDER BY host_id, name;
```

- `check_args` should show `{"port": 8080}` for TCP port 8080, `{"port": 8080, "path": "/", "expect": "200"}` for HTTP, etc.
- If you see `[]` or numeric keys, the pipeline is still sending/listifying overrides incorrectly.

### API

- **GET** `GET /api/workspaces/{id}/observe/targets` must return for each service an **`overrides`** field that is a JSON **object** (e.g. `{"port": 8080}`), never `[]`.

**Example check:**

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://your-api/api/workspaces/84/observe/targets" | jq '.data[].services[] | {name, overrides}'
```

- Every service should have `overrides` as `{}` or `{"key": value}`.

### Tracing (debug)

- **PUT:** Backend logs `ObserveTargets PUT overrides (before save)` and `ObserveTargets PUT overrides (after save)` with `workspace_id`, `incoming_overrides`, `normalized_check_args`, and `db_check_args_decoded`. Use these to confirm request → normalize → DB.
- **GET:** Backend logs `ObserveTargets GET overrides` with `workspace_id`, `service_id`, `check_args_raw`, and `response_overrides`. Use these to confirm DB → response shape.

Log level: ensure `LOG_LEVEL=debug` (or equivalent) to see these messages.

### Acceptance

1. Save & Publish with overrides (e.g. HTTP expect=200, TCP port=8080).
2. Reload page / logout–login.
3. GET `/observe/targets` shows overrides persisted (object).
4. UI shows the same values (200, 8080) without reverting to defaults.

---

## 2. Validating Services Page (Poll Fields + Auto-Refresh)

### Poll output

- The **poll** command (`observe:poll`) calls the gateway `/internal/engines/nagios/services?host_prefix=ws{id}-`. The gateway fetches Nagios **servicelist** and then **per-service detail** (statusjson.cgi `query=service`) to fill:
  - `last_check_at`, `last_state_change_at`, `duration_sec`, `attempt`, `current_attempt`, `max_attempts`
  - `output`, `plugin_output`, `long_plugin_output`, `perfdata`

- Backend stores these in `observe_services` and returns them from **GET** `/api/workspaces/{id}/observe/services`.

### API response

- **GET** `GET /api/workspaces/{id}/observe/services` returns `data.items[]` with at least:
  - `lastCheckAt`, `durationSec`, `attempt`, `info` (plugin output)
  - Optionally: `pluginOutput`, `longPluginOutput`, `perfData`, `currentAttempt`, `maxAttempts`, `lastStateChangeAt`, etc.

### UI

- **Services** page shows: Host, Service, Status, **Last Check**, **Duration**, **Attempt**, **Status Information**.
- **Auto-refresh:** The page refetches on the selected interval (e.g. 90 seconds). “Last poll” is shown in the stale-data banner when data is old.
- **Perf data:** Expandable row per service shows Perf data and Long output when available.

### Acceptance

1. After Save & Publish, run one poll: `php artisan observe:poll --workspace_id=<id>`.
2. Open Services page: all configured services for that workspace are listed.
3. Each row shows **Last Check** populated, **Attempt** (e.g. 1/3), **Status Information** populated.
4. When the dropdown interval elapses, the page auto-refreshes and updates without manual refresh.

---

## 3. Dead Buttons / Icons

- Any action that is **not implemented** must be **disabled** and show a **“Coming soon”** tooltip.
- No clickable buttons that do nothing (no dead ends).

Already disabled with “Coming soon”: Configure (Real-time Monitoring, Services), Export/Configure (Infrastructure Map, Performance Analytics, Capacity Planning), Add Data Source and row actions (Data Sources), Generate Report and download (Reports), Global Settings / Create Alert Rule and row actions (Alert Management), Settings / Create Instance and row actions (Instance Management), Acknowledge / Schedule Downtime (Services).

---

## 4. Known Limitations

- **Nagios binary path:** The gateway resolves the Nagios binary (env `NAGIOS_BIN` or `NAGIOS_BINARY_PATH`, default `nagios`, with fallbacks `/usr/local/bin/nagios`, `/opt/nagios/bin/nagios`). If your container uses a different path, set the env or rely on the fallback. `/ready` reports the resolved path in `checks.nagios_binary.path`.
- **Per-service detail fetch:** The gateway fetches each service’s detail from Nagios (statusjson.cgi `query=service`) with a concurrency limit. For very large numbers of services this may be slower; consider tuning cache TTL or concurrency if needed.
- **Stale threshold:** “Data may be stale” uses `observe.stale_threshold_seconds` (default 300). Adjust in backend config if needed.
- **Engine unreachable:** If the poll fails (e.g. gateway or Nagios down), the backend marks the engine as unreachable and the UI shows the “Engine unreachable” banner; services may show as unreachable or last known state.

---

## 5. Exact Files Changed (PR summary)

**Persistence (overrides) + tracing**

- `backend/app/Http/Controllers/ObserveTargetsController.php` – GET/PUT overrides tracing; ensure response overrides is object; log before/after save.

**Services (Nagios fields + poll + API + UI)**

- `backend/database/migrations/2026_01_25_000026_add_nagios_fields_to_observe_services_table.php` – new columns (next_check_at, current_attempt, max_attempts, state_type, plugin_output, long_plugin_output, check_command, check_latency_sec, execution_time_sec, last_state_change_at).
- `backend/app/Models/ObserveService.php` – fillable and casts for new fields.
- `backend/app/Console/Commands/PollObserveData.php` – map and store new fields from gateway response.
- `backend/app/Http/Controllers/ObserveController.php` – return new fields in services API items.
- `gateway/src/engines/nagios.ts` – extend NagiosService; fetch per-service detail with concurrency; normalize full fields.
- `frontend/src/types/observe.ts` – ObserveServiceRow extended with optional Nagios fields.
- `frontend/src/pages/observe/Services.tsx` – expandable row for Perf data / long output; ensure Last Check, Duration, Attempt, Status Information displayed; auto-refresh already wired via refreshKey.

**Doc**

- `docs/SHIELDOBSERVE_PROD_READINESS.md` – this file.
