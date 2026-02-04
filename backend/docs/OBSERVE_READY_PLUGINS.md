# Predefined NRPE / Ready Service Types

The observe engine includes **predefined "ready" service types** based on standard **Nagios Plugins** and common **NRPE** checks, including **infrastructure** (CPU, RAM, Disk, Load, Swap, Inodes) and network/database/SSL types. These mirror the [official nagios-plugins](https://nagios-plugins.org/doc/man/) so you can add services without writing scripts first. A **Custom Script** type lets you run your own plugin by name.

## How it works

- **Service definitions** are seeded from `ObserveServiceDefinitionSeeder` (base: ping, http, tcp_port, **Custom Script**, custom) and **`ObserveServiceDefinitionReadyPluginsSeeder`** (NRPE-style types).
- In **Monitored Targets**, when you add a service and choose a type (e.g. **CPU Usage**, **Disk Space**, **Custom Script**), the backend stores `service_key` and `check_command`.
- The **native engine** runs:
  - **http**, **tcp_port**, **ping** → built-in PHP checks.
  - **plugin** (Custom Script) → runs the script whose **name** you provide in the "plugin" argument (e.g. `my_check` → runs `my_check.sh` or `my_check.php` in the plugins dir).
  - **Any other type** (cpu, memory, disk, load, swap, ssh, mysql, etc.) → run as **plugin** with script name = `check_command` (e.g. `check_cpu`, `check_disk`). You need a script with that name in the plugins directory; the engine passes `OBSERVE_HOST_ADDRESS` and `OBSERVE_CHECK_ARGS` (JSON).

## Custom Script (user-defined plugin)

To **create your own check** and give it a name:

1. Add a service and choose type **Custom Script**.
2. In the configuration, set **plugin** to the **script name** (e.g. `my_health` or `check_custom_thing`). Do not include path or extension; the engine will look for `my_health.sh`, `my_health.php`, or `my_health.pl` in the plugins directory.
3. Put your script in the **plugins directory** (default `storage/app/observe_plugins/`) with that name and a supported extension (`.php`, `.pl`, `.sh`).
4. Your script receives env vars: `OBSERVE_HOST_ADDRESS`, `OBSERVE_CHECK_ARGS` (JSON), `OBSERVE_SERVICE_NAME`, `OBSERVE_HOST_NAME`, `OBSERVE_WORKSPACE_ID`. It must **exit** 0=OK, 1=Warning, 2=Critical, 3=Unknown and print one line to stdout (optional ` | perfdata` after the message).

Example: name = `my_health` → create `storage/app/observe_plugins/my_health.sh`, make it executable, and use the same exit-code convention.

## Seeded types (summary)

### Infrastructure (CPU, RAM, Disk, Load, Swap, Inodes)

| service_key | display_name    | check_command  | status |
|-------------|-----------------|----------------|--------|
| cpu         | CPU Usage       | check_cpu      | active |
| memory      | Memory (RAM)    | check_memory    | active |
| disk        | Disk Space      | check_disk     | active |
| inodes      | Disk Inodes     | check_inodes   | active |
| load        | Load            | check_load     | active |
| swap        | Swap            | check_swap     | active |

### System / users / processes

| service_key | display_name   | check_command | status   |
|-------------|----------------|---------------|----------|
| users       | Current Users  | check_users   | active   |
| procs       | Processes      | check_procs   | active   |
| uptime      | Uptime         | check_uptime  | active   |

### Network / protocols

| service_key | display_name | check_command     | status   |
|-------------|--------------|-------------------|----------|
| ntp_time    | NTP Time     | check_ntp_time    | active   |
| ssh         | SSH          | check_ssh         | active   |
| dns         | DNS          | check_dns         | active   |
| smtp        | SMTP         | check_smtp        | active   |
| ssl_validity| SSL Certificate | check_ssl_validity | active |
| imap, pop, ftp, ldap, ntp_peer | (various) | check_* | disabled |

### Databases

| service_key   | display_name | check_command      | status   |
|---------------|--------------|--------------------|----------|
| mysql         | MySQL        | check_mysql        | active   |
| pgsql         | PostgreSQL   | check_pgsql        | active   |
| mysql_query   | MySQL Query  | check_mysql_query  | disabled |
| oracle        | Oracle DB    | check_oracle       | disabled |

### Files / logs / other

| service_key | display_name   | check_command   | status   |
|-------------|----------------|-----------------|----------|
| file_age    | File Age       | check_file_age  | disabled |
| log         | Log            | check_log       | disabled |
| dig, dhcp, mailq, sensors, snmp, udp, time, by_ssh | (various) | check_* | disabled |
| apt, clamd, ide_smart, nt, nrpe, radius, rpc, nagios, mrtg, mrtgtraf | (various) | check_* | disabled |

You can **enable** any disabled type by setting `status` to `active` in the seeder and re-seeding, or by updating the row in the database.

```bash
# Re-seed only the ready plugins
php artisan db:seed --class=ObserveServiceDefinitionReadyPluginsSeeder
```

## Providing the plugin scripts

For each type (e.g. cpu, memory, disk), the engine runs a script whose **name** is the `check_command` (e.g. `check_cpu`, `check_memory`, `check_disk`). Scripts must live in the **plugins directory** (default `storage/app/observe_plugins/`):

- **Infrastructure:** Provide `check_cpu`, `check_memory`, `check_disk`, `check_inodes`, `check_load`, `check_swap` (PHP, Perl, or shell) that read `OBSERVE_HOST_ADDRESS` and `OBSERVE_CHECK_ARGS` and exit 0/1/2/3. CPU/Memory are often run via NRPE on the target host; you can use wrapper scripts that call NRPE or implement checks locally.
- **Nagios binaries:** Install **nagios-plugins** and symlink/copy binaries (e.g. `check_disk`, `check_load`) into the plugins dir. For binaries, use a small shell wrapper that passes args from env to the binary.
- **Examples:** See `backend/docs/observe_plugins_example/` (e.g. `check_disk.php`, `check_health.sh`) and copy/adapt into `storage/app/observe_plugins/`.
- **Important:** All plugin scripts must use the **host from the UI** via the `OBSERVE_HOST_ADDRESS` environment variable. Do not hardcode IPs (e.g. 127.0.0.1) in plugins; the engine passes the host entered under Monitored Targets. Configurable values (mount, warn_pct, crit_pct, port, etc.) must come from `OBSERVE_CHECK_ARGS` (from the service configuration in the UI).

## Full Nagios Plugins reference

The official suite includes 80+ plugins (see [nagios-plugins.org doc/man](https://nagios-plugins.org/doc/man/)). The seeder includes infrastructure (CPU, RAM, disk, inodes, load, swap), system, network, database, and optional types; add more rows to `ObserveServiceDefinitionReadyPluginsSeeder` as needed and set `status` to `active` or `disabled`.
