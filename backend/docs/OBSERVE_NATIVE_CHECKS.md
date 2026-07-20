# In-Platform Monitoring (Native QynSight — No Nagios Daemon)

> **v1.0.0 GA.** QynSight runs **native** checks inside Laravel. The gateway proxies `/api/*` to the
> backend only; there is no Nagios container or `docker-compose` stack in this repo. Operational
> procedures: **`docs/OBSERVE_RUNBOOK.md`**, **`DEPLOYMENT.md`** §7.

## Validation: Will it work?

**Yes.** The platform provides:

- **Data model:** Hosts and services (`ObserveTargetHost`, `ObserveTargetService`) with `check_args`, intervals, and retry policy.
- **Command resolution:** `ObserveServiceCommandResolver` for HTTP, TCP, Ping, and plugins.
- **Result storage:** `ObserveService` with `engine_key = 'native'` (same tables the UI reads).

The **scheduler** runs checks via `observe:run-checks` (every **two minutes** in `app/Console/Kernel.php`).

## Approach: Native check runner

1. **Scheduler** — Cron runs `php artisan schedule:run` every minute; Laravel invokes `observe:run-checks` on its schedule.
2. **Runner** — For each due target service, the app runs the check in PHP (HTTP client, TCP socket, ping, or plugin script).
3. **Result** — Map to ok / warning / critical; update `last_check_at`, `next_check_at`, `output`; upsert `ObserveService`.
4. **UI** — Reads `ObserveService`; no external daemon required.

## Engine mode

- **Native only (production default):** Use `observe:run-checks` + `observe:evaluate-alerts` on the scheduler. Do **not** set `OBSERVE_ENGINE_URL` on the gateway.
- **Legacy Nagios:** Removed as a platform dependency. Gateway returns `410 Gone` for `/internal/engines/nagios*`.

## Verify on a fresh deploy

1. Confirm crontab for `schedule:run` (see `DEPLOYMENT.md` §7).
2. Wait 2–3 minutes; check `backend/storage/logs/scheduler.log` for `observe:run-checks` output.
3. Manual run:

   ```bash
   cd backend && php artisan observe:run-checks --workspace_id=<id>
   ```

The Services UI reflects the last native check run.

## Plugins

Custom plugin scripts (PHP/Perl/shell) receive environment variables from the engine and use Nagios-style exit codes (0/1/2/3). Set **`OBSERVE_PHP_CLI`** in `.env` to the CLI PHP binary (not php-fpm) when plugins need PHP.

See **`backend/docs/OBSERVE_READY_PLUGINS.md`** for ready-made check types.

## Related commands

| Command | Purpose |
|---------|---------|
| `observe:run-checks` | Run due native checks |
| `observe:evaluate-alerts` | Alert evaluation (every minute) |
| `observe:run-port-scans` | Scheduled port scans (every five minutes; also uses queue for large scans) |
| `observe:install-plugins` | Install/sync plugin definitions |
