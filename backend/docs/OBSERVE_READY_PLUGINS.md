# Predefined NRPE / Ready Service Types

The observe engine includes **predefined "ready" service types** based on standard **Nagios Plugins** and common **NRPE** checks. These mirror the [official nagios-plugins](https://nagios-plugins.org/doc/man/) so you can add services (Disk, Load, SSH, MySQL, etc.) without writing scripts first.

## How it works

- **Service definitions** are seeded from `ObserveServiceDefinitionSeeder` (base: ping, http, tcp_port, plugin, custom) and **`ObserveServiceDefinitionReadyPluginsSeeder`** (NRPE-style types).
- In **Monitored Targets**, when you add a service and choose a type (e.g. **Disk Space**, **Load**, **SSH**), the backend stores `service_key` (e.g. `disk`, `load`, `ssh`) and `check_command` (e.g. `check_disk`, `check_load`, `check_ssh`).
- The **native engine** runs:
  - **http**, **tcp_port**, **ping** → built-in PHP checks.
  - **plugin** → custom script (name from check_args.plugin).
  - **Any other type** (disk, load, swap, ssh, mysql, etc.) → run as **plugin** with script name = `check_command` (e.g. `check_disk`). So you must have a script in the plugins dir with that name (e.g. `check_disk.php` or `check_disk.sh`) that accepts the same args; the engine passes `OBSERVE_HOST_ADDRESS` and `OBSERVE_CHECK_ARGS` (JSON).

So for "Disk Space" to work you need a plugin script named **check_disk** (or check_disk.php) in `storage/app/observe_plugins/` that reads args (e.g. mount, warn_pct, crit_pct) from the environment and exits 0/1/2/3 with one line of output.

## Seeded NRPE-style types (summary)

| service_key   | display_name       | check_command     | status   |
|---------------|--------------------|-------------------|----------|
| disk          | Disk Space         | check_disk        | active   |
| load          | Load               | check_load        | active   |
| swap          | Swap               | check_swap        | active   |
| users         | Current Users      | check_users       | active   |
| procs         | Processes          | check_procs       | active   |
| uptime        | Uptime             | check_uptime      | active   |
| ntp_time      | NTP Time           | check_ntp_time    | active   |
| ssh           | SSH                | check_ssh         | active   |
| dns           | DNS                | check_dns         | active   |
| smtp          | SMTP               | check_smtp        | active   |
| mysql         | MySQL              | check_mysql       | active   |
| pgsql         | PostgreSQL         | check_pgsql       | active   |
| ssl_validity  | SSL Certificate    | check_ssl_validity| active   |
| imap, pop, ftp, ldap, ntp_peer, file_age, log, dig, dhcp, mailq, sensors, snmp, udp, time, by_ssh | (various) | (check_*) | disabled |

You can **remove or add** types by changing **status** in the database or in the seeder and re-running:

- **active** → type appears in the UI when adding a service.
- **disabled** → hidden; set to `active` to "add" it.

```bash
# Re-seed only the ready plugins (add/remove by editing the seeder or DB)
php artisan db:seed --class=ObserveServiceDefinitionReadyPluginsSeeder
```

## Providing the plugin scripts

For each NRPE-style type (e.g. disk, load), the engine will run a script whose **name** is the `check_command` (e.g. `check_disk`). So you need that script in the **plugins directory** (default `storage/app/observe_plugins/`):

- Either copy or write **check_disk**, **check_load**, **check_swap**, etc. (PHP, Perl, or shell) that use `OBSERVE_HOST_ADDRESS` and `OBSERVE_CHECK_ARGS` and exit 0/1/2/3.
- Or install **nagios-plugins** on the server and symlink/copy the binaries (e.g. `check_disk`) into the plugins dir; the runner runs scripts by name (`.php` → php, `.pl` → perl, else → bash). For a binary you could add a tiny shell wrapper that calls the binary with args from env.

Example: for **check_disk** you can use the example in `backend/docs/observe_plugins_example/check_disk.php` and copy it to `storage/app/observe_plugins/check_disk.php`.

## Full Nagios Plugins list (reference)

The official suite includes 80+ plugins (see [nagios-plugins.org doc/man](https://nagios-plugins.org/doc/man/)). The seeder includes the most common NRPE ones; you can add more rows to `ObserveServiceDefinitionReadyPluginsSeeder` for check_apt, check_clamd, check_http (we have native), check_icmp, check_ldaps, check_mysql_query, check_nt, check_oracle, check_radius, check_rpc, etc., and set `status` to `disabled` or `active` as needed.
