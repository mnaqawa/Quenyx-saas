# In-Platform Monitoring (No Nagios Daemon)

## Validation: Will it work?

**Yes.** You do **not** need to reimplement or analyze the full Nagios Core codebase. The platform already has:

- **Data model:** Hosts and services (ObserveTargetHost, ObserveTargetService) with check_args, check_interval, retry_interval.
- **Command resolution:** ObserveServiceCommandResolver knows how to interpret check_args for HTTP, TCP, and Ping.
- **Result storage:** ObserveService stores state, last_check_at, next_check_at, output (same table the UI reads).

What was missing was **who runs the checks**. Today Nagios runs them and we poll its API; if the poll or Nagios scheduler is misconfigured, the UI goes stale.

## Approach: Native check runner inside the platform

1. **Scheduler** – Laravel schedule runs `observe:run-checks` every minute (or more often).
2. **Runner** – For each enabled target service whose "next check" time is due (or never run), the app runs the check **inside the app**:
   - **HTTP:** PHP HTTP client to `host:port/path`, compare status to expected (e.g. 200).
   - **TCP:** PHP socket connect to `host:port`.
   - **Ping:** Execute system `ping` (or a simple socket) and parse RTA/loss.
3. **Result** – Map exit/response to state (ok / warning / critical), set last_check_at, next_check_at, output; upsert into ObserveService with `engine_key = 'native'`.
4. **UI** – Unchanged; it already reads ObserveService. No gateway, no Nagios daemon, no poll from Nagios.

## What is *not* reimplemented (and not required for this)

- Full Nagios Core (flapping, downtime windows, event handlers, distributed checks, etc.).
- Nagios plugins as binaries (we use native PHP/HTTP/sockets instead).
- Nagios web UI or CGIs.

## Engine mode

- **Native only:** Use `observe:run-checks` on a schedule; do not start Nagios or the gateway for observe. Services page shows data from `engine_key = 'native'`.
- **Nagios (optional):** Keep Publish + gateway + poll for teams that still want Nagios. You can run both and show both in the UI, or switch to native only.

## Stop Nagios for native-only testing

You do **not** need Nagios when using the native engine. To test without Nagios:

1. **Stop the Nagios container:**
   ```bash
   docker-compose -f docker-compose.nagios.yml down
   ```
2. **Optional:** Stop or disable the observe poll so it does not try to reach the gateway:
   - Comment out or remove the `observe:poll` schedule in `app/Console/Kernel.php`, or
   - Leave it; it will set `engine_unreachable` for nagios but native data will still show (deduped, native preferred).
3. Ensure the Laravel scheduler is running so `observe:run-checks` runs every minute:
   ```bash
   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
   ```
4. Run checks once manually if needed:
   ```bash
   cd backend && php artisan observe:run-checks --workspace_id=84
   ```

The Services UI will show native results; "last poll" will reflect the last `observe:run-checks` run.

---

## Observe plugins (Nagios-style custom scripts)

The native engine can run **custom plugin scripts** (PHP, Perl, shell) so you can monitor anything the built-in checks don’t cover.

### How it works

- **Plugin directory:** Scripts live under the configured plugins dir (default: `storage/app/observe_plugins`). Set `OBSERVE_PLUGINS_DIR` in `.env` to override (path relative to `storage_path()` or absolute).
- **Service type:** Add a service with **service_key** `plugin` and **check_args** containing at least `plugin` (script name or path relative to plugins dir). Other keys in `check_args` are passed to the script as JSON in `OBSERVE_CHECK_ARGS`.
- **Execution:** The engine runs the script with environment variables set (see below), waits for exit (timeout from `observe.plugin_timeout_seconds`), then maps **exit code** to status and uses **stdout** as the check output.

### Environment variables passed to plugins

| Variable | Description |
|----------|-------------|
| `OBSERVE_HOST_ADDRESS` | Host IP or hostname from the monitored target |
| `OBSERVE_HOST_NAME` | Scoped host name (e.g. `ws84-MyHost`) |
| `OBSERVE_SERVICE_NAME` | Service name (e.g. `Disk /`) |
| `OBSERVE_WORKSPACE_ID` | Workspace ID |
| `OBSERVE_CHECK_ARGS` | JSON object of check_args (e.g. `{"plugin":"check_disk","mount":"/"}`) |

### Exit codes (Nagios convention)

| Code | Status |
|------|--------|
| 0 | OK |
| 1 | Warning |
| 2 | Critical |
| 3 | Unknown |

### Output

- **Stdout:** First line is the short status message shown in the UI. Optional: append ` | perfdata` (space-pipe-space) for performance data.
- **Stderr:** Logged by the engine but not stored as the main output.

### Supported script types

- **PHP:** `script.php` — run with `php script.php`
- **Perl:** `script.pl` — run with `perl script.pl`
- **Shell:** `script.sh` (or no extension) — run with `bash script.sh` (or `sh`)

Only scripts inside the plugins directory are allowed; path traversal is blocked.

### Example plugin (PHP)

```php
#!/usr/bin/env php
<?php
// storage/app/observe_plugins/check_disk.php
$args = json_decode(getenv('OBSERVE_CHECK_ARGS') ?: '{}', true);
$mount = $args['mount'] ?? '/';
$host = getenv('OBSERVE_HOST_ADDRESS');
// ... check disk on $host (e.g. SSH or local if host is localhost) ...
$free = 50; // percent free
if ($free >= 20) { echo "DISK OK - {$free}% free\n"; exit(0); }
if ($free >= 10) { echo "DISK WARNING - {$free}% free\n"; exit(1); }
echo "DISK CRITICAL - {$free}% free\n"; exit(2);
```

### Example plugin (shell)

```bash
#!/bin/bash
# storage/app/observe_plugins/check_myapp.sh
HOST="${OBSERVE_HOST_ADDRESS:-127.0.0.1}"
ARGS=$(echo "$OBSERVE_CHECK_ARGS" | php -r 'echo json_decode(file_get_contents("php://stdin"))->port ?? 8080;')
if curl -sf "http://$HOST:$ARGS/health" > /dev/null; then
  echo "OK - health endpoint responded"
  exit 0
else
  echo "CRITICAL - health endpoint failed"
  exit 2
fi
```

### Adding a plugin service in the UI

1. In **Monitored Targets**, add a service and set its type to **Plugin** (or the service definition with `service_key` = `plugin`).
2. In check_args (or overrides), set at least:
   - `plugin`: script name, e.g. `check_disk` or `check_disk.php` (must exist in the plugins dir).
3. Add any other arguments (e.g. `mount`, `port`); they will be in `OBSERVE_CHECK_ARGS` for the script.

---

## Summary

Running the full "core" of checks **inside the platform** is done by a scheduled command that runs HTTP/TCP/Ping checks in PHP and writes to ObserveService. **Plugins** allow custom PHP/Perl/shell scripts to run with variables from the engine and return status. No Nagios daemon required; use `observe:run-checks` and optionally stop Nagios with `docker-compose -f docker-compose.nagios.yml down`.
