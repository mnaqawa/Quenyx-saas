# Overrides Persistence + Engine Unreachable – Root Cause and Fixes

> **⚠️ PARTIALLY SUPERSEDED / LEGACY (pre‑RC1.1).** Part A (overrides persistence) remains accurate.
> The "engine unreachable" analysis refers to the former **Nagios‑based** poll path (`observe:poll`
> calling the gateway `/internal/engines/nagios/*`), which has been **removed**: monitoring is now
> **native** (`observe:run-checks`) and that gateway path returns `410 Gone`. See
> **[`docs/OBSERVE_RUNBOOK.md`](./OBSERVE_RUNBOOK.md)** for the current native runbook.

## Part A — Why overrides were being dropped

**Root cause:** The request body is decoded by Laravel from JSON. When the frontend sends `overrides: { "port": 8081 }`, PHP’s `json_decode()` can return that value as a **stdClass** object (not an array) if the payload was decoded without the “associative” flag. The previous `normalizeIncomingOverrides()` only handled arrays:

- `if (! is_array($overrides)) return [];`

So any **stdClass** (e.g. `(object)['port' => 8081]`) was treated as non-array and **normalized to `[]`**. That empty array was stored in `check_args` and returned as `overrides: {}`, so the UI fell back to defaults (e.g. port 80).

**What was changed:**

1. **`normalizeIncomingOverrides()`** now:
   - Handles **null** → return `[]`.
   - Handles **stdClass** by converting to an associative array:  
     `$overrides = json_decode(json_encode($overrides), true);`  
     so JSON objects from the request are preserved as key/value pairs.
   - Handles **arrays**: if it’s a list (numeric keys 0,1,2,…) → return `[]`; otherwise return the associative array as-is.
   - Uses `array_is_list()` when available (PHP 8.1+), with a fallback for older PHP.

2. **Single assignment of `check_args`:**  
   `$serviceData['check_args']` is set **once** from the normalized overrides at the start of the service validation loop. The branch that resolves `service_key` → `check_command` no longer overwrites `check_args` (the redundant `$serviceData['check_args'] = $overrides` there was removed).

3. **Temporary debug logging:**  
   For each service, a log entry was added with:
   - `service_name`
   - `gettype($serviceData['overrides'])`
   - `json_encode($serviceData['overrides'])`
   - `connection` and `database`

   **After verification,** remove the temporary block that logs `ObserveTargets PUT incoming overrides (raw)` (the `Log::info('ObserveTargets PUT incoming overrides (raw)', [...])` and its array) from `ObserveTargetsController@update`.

4. **Response:**  
   The PUT response was already built from a **fresh query** after the transaction (`ObserveTargetHost::where(...)->with('services')->get()`), so `overrides` and `check_args` in the response come from the **persisted** `check_args` in the DB. No change was required there; with correct normalization and storage, the response now shows e.g. `overrides: { "port": 8081 }`.

**Acceptance:**  
Set TCP port to 8081 → Save & Publish → DB `observe_targets_services.check_args` is JSON like `{"port":8081}` → reload / navigate away / logout–login → UI still shows 8081. Then remove the temporary debug logging.

---

## Part B — Why the engine was unreachable

**Root cause:** The **poll** command (`observe:poll`) calls the gateway at `{gateway_url}/internal/engines/nagios/services` with header `x-internal-secret`. When that request fails (connection refused, timeout, 404, wrong secret, gateway down, etc.), the exception message is stored in `ObserveMeta.error` for that workspace/engine. The Services API already set `engine_unreachable = !empty($meta->error)`, but the **exact failure reason** was not returned to the UI, so the UI only showed “Monitoring engine is unreachable” with no actionable cause.

**What was changed:**

1. **Services API** now returns **`engine_unreachable_reason`** when the engine is unreachable:
   - Value is the stored `ObserveMeta.error` string (e.g. connection refused, timeout, “Gateway returned 404”, “Invalid response format from gateway”, bad internal secret leading to 401/403, etc.).

2. **Frontend** Services page shows this reason when present:
   - Under “Monitoring engine is unreachable. Status may be outdated.” it displays:  
     **“Reason: &lt;engine_unreachable_reason&gt;”**  
   so you can see whether the failure is connection, timeout, 404, auth, or something else.

3. **Poll and gateway:**
   - Poll already uses `config('app.gateway_url')` and `config('app.gateway_internal_secret')` and sends `x-internal-secret` on the request. Ensure `.env` (or config) has the correct `GATEWAY_URL` (or `APP_GATEWAY_URL` as mapped in `config/app.php`) and `GATEWAY_INTERNAL_SECRET` matching the gateway. The gateway must be running and the internal route `/internal/engines/nagios/services` must exist (gateway must be up to date). The gateway then needs to reach the Nagios container and status (e.g. statusjson.cgi); if that fails, the gateway returns an error and the poll stores that message in `ObserveMeta.error`, which is now visible as `engine_unreachable_reason`.

**Acceptance:**  
When the engine is healthy, the Services page no longer shows “engine unreachable” and Last Check / Status Information populate. When the engine is unreachable, the UI shows the concrete reason (e.g. “Connection refused”, “Gateway returned 404: …”, “Invalid response format from gateway”) so you can fix gateway URL, secret, or Nagios connectivity.

---

## Files changed

| File | Change |
|------|--------|
| `backend/app/Http/Controllers/ObserveTargetsController.php` | `normalizeIncomingOverrides()` handles null, stdClass, and list; single assignment of `check_args`; temporary per-service debug log (remove after verification); removed duplicate `check_args` assignment in def branch; removed standalone DB log. |
| `backend/app/Http/Controllers/ObserveController.php` | Added `engine_unreachable_reason` to services API response when `engine_unreachable` is true. |
| `frontend/src/types/observe.ts` | Added `engine_unreachable_reason?: string \| null` to `ObserveServicesResponse`. |
| `frontend/src/pages/observe/Services.tsx` | Banner shows “Reason: &lt;engine_unreachable_reason&gt;” when engine is unreachable and reason is present. |
| `docs/SHIELDOBSERVE_OVERRIDES_AND_ENGINE_FIX.md` | This summary. |
