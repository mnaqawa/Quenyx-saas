<?php

namespace Database\Seeders;

use App\Models\ObserveServiceDefinition;
use Illuminate\Database\Seeder;

/**
 * Predefined "ready" service types based on standard Nagios Plugins / NRPE.
 * These mirror the official nagios-plugins (https://nagios-plugins.org/doc/man/).
 * You can remove or add: set status to 'disabled' to hide, 'active' to show.
 * Execution: native engine runs them as plugins (script name = check_command) when not http/tcp_port/ping.
 */
class ObserveServiceDefinitionReadyPluginsSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = $this->getNrpeReadyDefinitions();
        foreach ($definitions as $attrs) {
            ObserveServiceDefinition::updateOrCreate(
                [
                    'engine' => $attrs['engine'],
                    'service_key' => $attrs['service_key'],
                ],
                $attrs
            );
        }
    }

    /**
     * NRPE / standard Nagios plugin definitions. status: active = shown by default, disabled = add when needed.
     */
    private function getNrpeReadyDefinitions(): array
    {
        return [
            // ---- Infrastructure: CPU, Memory, Disk, Load, Swap ----
            [
                'engine' => 'nagios',
                'service_key' => 'cpu',
                'display_name' => 'CPU Usage',
                'description' => 'Monitors CPU utilization (%). Alerts when usage exceeds warning or critical thresholds. Helps avoid overload and plan capacity. Typically run via NRPE on the target host.',
                'check_command' => 'check_cpu',
                'args_schema' => [
                    ['position' => 0, 'key' => 'warn_pct', 'default' => 80, 'required' => false],
                    ['position' => 1, 'key' => 'crit_pct', 'default' => 95, 'required' => false],
                ],
                'capability_flags' => ['supports_thresholds'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'memory',
                'display_name' => 'Memory (RAM)',
                'description' => 'Monitors RAM usage (%). Alerts when free memory drops below thresholds. Essential for preventing OOM and understanding memory pressure on servers.',
                'check_command' => 'check_memory',
                'args_schema' => [
                    ['position' => 0, 'key' => 'warn_pct', 'default' => 85, 'required' => false],
                    ['position' => 1, 'key' => 'crit_pct', 'default' => 95, 'required' => false],
                    ['position' => 2, 'key' => 'type', 'default' => 'used', 'required' => false],
                ],
                'capability_flags' => ['supports_thresholds'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'disk',
                'display_name' => 'Disk Space',
                'description' => 'Checks free disk space on a mount (e.g. / or /data). Alerts when free space falls below warning or critical %. Prevents out-of-disk failures.',
                'check_command' => 'check_disk',
                'args_schema' => [
                    ['position' => 0, 'key' => 'mount', 'default' => '/', 'required' => false],
                    ['position' => 1, 'key' => 'warn_pct', 'default' => 20, 'required' => false],
                    ['position' => 2, 'key' => 'crit_pct', 'default' => 10, 'required' => false],
                ],
                'capability_flags' => ['supports_thresholds'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'inodes',
                'display_name' => 'Disk Inodes',
                'description' => 'Checks free inode count on a filesystem. Even with free space, running out of inodes can break writes. Use for mounts with many small files.',
                'check_command' => 'check_inodes',
                'args_schema' => [
                    ['position' => 0, 'key' => 'mount', 'default' => '/', 'required' => false],
                    ['position' => 1, 'key' => 'warn_pct', 'default' => 15, 'required' => false],
                    ['position' => 2, 'key' => 'crit_pct', 'default' => 5, 'required' => false],
                ],
                'capability_flags' => ['supports_thresholds'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'load',
                'display_name' => 'Load',
                'description' => 'Monitors system load average (1, 5, 15 min). High load relative to CPU count indicates the system is overloaded. Configurable warn/crit per interval.',
                'check_command' => 'check_load',
                'args_schema' => [
                    ['position' => 0, 'key' => 'warn_1', 'default' => null, 'required' => false],
                    ['position' => 1, 'key' => 'warn_5', 'default' => null, 'required' => false],
                    ['position' => 2, 'key' => 'warn_15', 'default' => null, 'required' => false],
                    ['position' => 3, 'key' => 'crit_1', 'default' => null, 'required' => false],
                    ['position' => 4, 'key' => 'crit_5', 'default' => null, 'required' => false],
                    ['position' => 5, 'key' => 'crit_15', 'default' => null, 'required' => false],
                ],
                'capability_flags' => ['supports_thresholds'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'swap',
                'display_name' => 'Swap',
                'description' => 'Monitors swap usage. High swap can indicate memory pressure. Set warn/crit on free swap % to catch memory issues before OOM.',
                'check_command' => 'check_swap',
                'args_schema' => [
                    ['position' => 0, 'key' => 'warn_pct', 'default' => null, 'required' => false],
                    ['position' => 1, 'key' => 'crit_pct', 'default' => null, 'required' => false],
                ],
                'capability_flags' => ['supports_thresholds'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'users',
                'display_name' => 'Current Users',
                'description' => 'Checks the number of logged-in users. Useful to alert on unexpected logins or to cap concurrent users on shared systems.',
                'check_command' => 'check_users',
                'args_schema' => [
                    ['position' => 0, 'key' => 'warn', 'default' => null, 'required' => false],
                    ['position' => 1, 'key' => 'crit', 'default' => null, 'required' => false],
                ],
                'capability_flags' => ['supports_thresholds'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'procs',
                'display_name' => 'Processes',
                'description' => 'Checks process count (total or by state). Alerts if too many or too few processes are running—useful for daemons that should always be present.',
                'check_command' => 'check_procs',
                'args_schema' => [
                    ['position' => 0, 'key' => 'warn', 'default' => null, 'required' => false],
                    ['position' => 1, 'key' => 'crit', 'default' => null, 'required' => false],
                    ['position' => 2, 'key' => 'state', 'default' => null, 'required' => false],
                ],
                'capability_flags' => ['supports_thresholds'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'uptime',
                'display_name' => 'Uptime',
                'description' => 'Reports system uptime. Useful to confirm the host has not been rebooted unexpectedly and for basic availability tracking.',
                'check_command' => 'check_uptime',
                'args_schema' => [],
                'capability_flags' => [],
                'status' => 'active',
            ],
            // ---- Network / protocols ----
            [
                'engine' => 'nagios',
                'service_key' => 'ntp_time',
                'display_name' => 'NTP Time',
                'description' => 'Checks time offset against an NTP server. Critical for time-sensitive apps and certificates. Alerts when drift exceeds warn/crit thresholds.',
                'check_command' => 'check_ntp_time',
                'args_schema' => [
                    ['position' => 0, 'key' => 'warn_offset', 'default' => null, 'required' => false],
                    ['position' => 1, 'key' => 'crit_offset', 'default' => null, 'required' => false],
                ],
                'capability_flags' => ['supports_thresholds'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'ntp_peer',
                'display_name' => 'NTP Peer',
                'description' => 'Checks NTP peer/server reachability and sync state. Use when you need to verify the host is syncing with NTP.',
                'check_command' => 'check_ntp_peer',
                'args_schema' => [],
                'capability_flags' => [],
                'status' => 'disabled',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'ssh',
                'display_name' => 'SSH',
                'description' => 'Verifies the SSH daemon is listening and responding on the given port. Ensures remote access is available and the service has not crashed.',
                'check_command' => 'check_ssh',
                'args_schema' => [
                    ['position' => 0, 'key' => 'port', 'default' => 22, 'required' => false],
                ],
                'capability_flags' => ['supports_ports'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'dns',
                'display_name' => 'DNS',
                'description' => 'Checks DNS resolution (optionally against a specific server). Ensures DNS is working so name-based services and certs remain valid.',
                'check_command' => 'check_dns',
                'args_schema' => [
                    ['position' => 0, 'key' => 'server', 'default' => null, 'required' => false],
                    ['position' => 1, 'key' => 'hostname', 'default' => null, 'required' => false],
                ],
                'capability_flags' => [],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'smtp',
                'display_name' => 'SMTP',
                'description' => 'Checks that the SMTP server accepts connections (and optionally responds). Use to monitor mail servers and alert on delivery failures.',
                'check_command' => 'check_smtp',
                'args_schema' => [
                    ['position' => 0, 'key' => 'port', 'default' => 25, 'required' => false],
                ],
                'capability_flags' => ['supports_ports'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'imap',
                'display_name' => 'IMAP',
                'description' => 'Checks IMAP server availability on the given port. Use to monitor mail storage and retrieval services.',
                'check_command' => 'check_imap',
                'args_schema' => [
                    ['position' => 0, 'key' => 'port', 'default' => 143, 'required' => false],
                ],
                'capability_flags' => ['supports_ports'],
                'status' => 'disabled',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'pop',
                'display_name' => 'POP3',
                'description' => 'Checks POP3 server availability. Use to ensure mail retrieval is working for POP-based clients.',
                'check_command' => 'check_pop',
                'args_schema' => [
                    ['position' => 0, 'key' => 'port', 'default' => 110, 'required' => false],
                ],
                'capability_flags' => ['supports_ports'],
                'status' => 'disabled',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'ftp',
                'display_name' => 'FTP',
                'description' => 'Checks FTP server connectivity. Use to monitor file transfer services.',
                'check_command' => 'check_ftp',
                'args_schema' => [
                    ['position' => 0, 'key' => 'port', 'default' => 21, 'required' => false],
                ],
                'capability_flags' => ['supports_ports'],
                'status' => 'disabled',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'ldap',
                'display_name' => 'LDAP',
                'description' => 'Checks LDAP directory server availability. Use to ensure authentication and directory services are up.',
                'check_command' => 'check_ldap',
                'args_schema' => [
                    ['position' => 0, 'key' => 'port', 'default' => 389, 'required' => false],
                ],
                'capability_flags' => ['supports_ports'],
                'status' => 'disabled',
            ],
            // ---- Databases ----
            [
                'engine' => 'nagios',
                'service_key' => 'mysql',
                'display_name' => 'MySQL',
                'description' => 'Checks MySQL/MariaDB server connectivity and optionally authentication. Ensures the database is accepting connections for your apps.',
                'check_command' => 'check_mysql',
                'args_schema' => [
                    ['position' => 0, 'key' => 'port', 'default' => 3306, 'required' => false],
                    ['position' => 1, 'key' => 'user', 'default' => null, 'required' => false],
                    ['position' => 2, 'key' => 'password', 'default' => null, 'required' => false],
                ],
                'capability_flags' => ['supports_ports', 'supports_auth'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'pgsql',
                'display_name' => 'PostgreSQL',
                'description' => 'Checks PostgreSQL server connectivity. Use to ensure the database is up and accepting connections.',
                'check_command' => 'check_pgsql',
                'args_schema' => [
                    ['position' => 0, 'key' => 'port', 'default' => 5432, 'required' => false],
                    ['position' => 1, 'key' => 'user', 'default' => null, 'required' => false],
                ],
                'capability_flags' => ['supports_ports', 'supports_auth'],
                'status' => 'active',
            ],
            // ---- Files / logs ----
            [
                'engine' => 'nagios',
                'service_key' => 'file_age',
                'display_name' => 'File Age',
                'description' => 'Checks that a file exists and is not older than warn/crit seconds. Use for backup freshness or log/cron job monitoring.',
                'check_command' => 'check_file_age',
                'args_schema' => [
                    ['position' => 0, 'key' => 'path', 'default' => null, 'required' => true],
                    ['position' => 1, 'key' => 'warn_sec', 'default' => null, 'required' => false],
                    ['position' => 2, 'key' => 'crit_sec', 'default' => null, 'required' => false],
                ],
                'capability_flags' => ['supports_thresholds'],
                'status' => 'disabled',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'log',
                'display_name' => 'Log',
                'description' => 'Searches a log file for a pattern and alerts when found. Use to catch errors or security events in application logs.',
                'check_command' => 'check_log',
                'args_schema' => [
                    ['position' => 0, 'key' => 'logfile', 'default' => null, 'required' => true],
                    ['position' => 1, 'key' => 'pattern', 'default' => null, 'required' => true],
                ],
                'capability_flags' => [],
                'status' => 'disabled',
            ],
            // ---- SSL / HTTP variants ----
            [
                'engine' => 'nagios',
                'service_key' => 'ssl_validity',
                'display_name' => 'SSL Certificate',
                'description' => 'Checks TLS/SSL certificate expiration for the given host:port. Alerts when the cert will expire within warn/crit days so you can renew in time.',
                'check_command' => 'check_ssl_validity',
                'args_schema' => [
                    ['position' => 0, 'key' => 'port', 'default' => 443, 'required' => false],
                    ['position' => 1, 'key' => 'warn_days', 'default' => null, 'required' => false],
                    ['position' => 2, 'key' => 'crit_days', 'default' => null, 'required' => false],
                ],
                'capability_flags' => ['supports_ports', 'supports_thresholds'],
                'status' => 'active',
            ],
            // ---- More (disabled by default; enable as needed) ----
            ['engine' => 'nagios', 'service_key' => 'dig', 'display_name' => 'DNS (dig)', 'description' => 'DNS lookup using dig; useful for debugging or specific record checks.', 'check_command' => 'check_dig', 'args_schema' => [['position' => 0, 'key' => 'server', 'default' => null, 'required' => false]], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'dhcp', 'display_name' => 'DHCP', 'description' => 'Checks DHCP server response. Use to ensure DHCP is available on the network.', 'check_command' => 'check_dhcp', 'args_schema' => [], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'mailq', 'display_name' => 'Mail Queue', 'description' => 'Monitors mail queue length. Alerts when queued messages exceed thresholds.', 'check_command' => 'check_mailq', 'args_schema' => [['position' => 0, 'key' => 'warn', 'default' => null, 'required' => false], ['position' => 1, 'key' => 'crit', 'default' => null, 'required' => false]], 'capability_flags' => ['supports_thresholds'], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'sensors', 'display_name' => 'Hardware Sensors', 'description' => 'Checks hardware sensors (temperature, fan, voltage) via lm_sensors.', 'check_command' => 'check_sensors', 'args_schema' => [], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'snmp', 'display_name' => 'SNMP', 'description' => 'Queries an SNMP OID. Use to monitor network devices or any SNMP-exposed metrics.', 'check_command' => 'check_snmp', 'args_schema' => [['position' => 0, 'key' => 'oid', 'default' => null, 'required' => false]], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'udp', 'display_name' => 'UDP Port', 'description' => 'Checks that a UDP port is open. Use for UDP-based services (DNS, NTP, etc.).', 'check_command' => 'check_udp', 'args_schema' => [['position' => 0, 'key' => 'port', 'default' => null, 'required' => true]], 'capability_flags' => ['supports_ports'], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'time', 'display_name' => 'Time (TCP)', 'description' => 'Checks TCP time protocol (port 37). Use for legacy time sync checks.', 'check_command' => 'check_time', 'args_schema' => [['position' => 0, 'key' => 'port', 'default' => 37, 'required' => false]], 'capability_flags' => ['supports_ports'], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'by_ssh', 'display_name' => 'By SSH (remote check)', 'description' => 'Runs a remote command over SSH. Use to execute checks on the target host via SSH.', 'check_command' => 'check_by_ssh', 'args_schema' => [['position' => 0, 'key' => 'command', 'default' => null, 'required' => true]], 'capability_flags' => [], 'status' => 'disabled'],
            // ---- More infrastructure / system ----
            ['engine' => 'nagios', 'service_key' => 'apt', 'display_name' => 'APT Updates', 'description' => 'Counts pending APT package updates. Alerts when security or total updates exceed thresholds.', 'check_command' => 'check_apt', 'args_schema' => [['position' => 0, 'key' => 'warn', 'default' => null, 'required' => false], ['position' => 1, 'key' => 'crit', 'default' => null, 'required' => false]], 'capability_flags' => ['supports_thresholds'], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'clamd', 'display_name' => 'ClamAV Daemon', 'description' => 'Checks ClamAV daemon availability. Use to ensure antivirus scanning is operational.', 'check_command' => 'check_clamd', 'args_schema' => [['position' => 0, 'key' => 'port', 'default' => 3310, 'required' => false]], 'capability_flags' => ['supports_ports'], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'ide_smart', 'display_name' => 'IDE/SMART Disk', 'description' => 'Checks disk health via SMART. Use to predict disk failures before they occur.', 'check_command' => 'check_ide_smart', 'args_schema' => [['position' => 0, 'key' => 'device', 'default' => null, 'required' => true]], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'nt', 'display_name' => 'Windows (check_nt)', 'description' => 'Monitors Windows hosts via NSClient (CPU, memory, disk, services, etc.).', 'check_command' => 'check_nt', 'args_schema' => [['position' => 0, 'key' => 'variable', 'default' => 'CPULOAD', 'required' => false], ['position' => 1, 'key' => 'warn', 'default' => null, 'required' => false], ['position' => 2, 'key' => 'crit', 'default' => null, 'required' => false]], 'capability_flags' => ['supports_thresholds'], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'nrpe', 'display_name' => 'NRPE (remote)', 'description' => 'Runs an NRPE command on the remote host. Use when the check must run on the target (e.g. local disk).', 'check_command' => 'check_nrpe', 'args_schema' => [['position' => 0, 'key' => 'command', 'default' => null, 'required' => true]], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'oracle', 'display_name' => 'Oracle DB', 'description' => 'Checks Oracle database connectivity. Use to ensure Oracle is accepting connections.', 'check_command' => 'check_oracle', 'args_schema' => [['position' => 0, 'key' => 'user', 'default' => null, 'required' => false], ['position' => 1, 'key' => 'password', 'default' => null, 'required' => false], ['position' => 2, 'key' => 'sid', 'default' => null, 'required' => false]], 'capability_flags' => ['supports_auth'], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'radius', 'display_name' => 'RADIUS', 'description' => 'Checks RADIUS server authentication. Use for VPN or AAA monitoring.', 'check_command' => 'check_radius', 'args_schema' => [['position' => 0, 'key' => 'secret', 'default' => null, 'required' => false]], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'rpc', 'display_name' => 'RPC', 'description' => 'Checks that an RPC program is registered. Use for NFS and other RPC-based services.', 'check_command' => 'check_rpc', 'args_schema' => [['position' => 0, 'key' => 'program', 'default' => null, 'required' => true]], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'mysql_query', 'display_name' => 'MySQL Query', 'description' => 'Runs a MySQL query and checks result against warn/crit. Use for replication lag, queue depth, etc.', 'check_command' => 'check_mysql_query', 'args_schema' => [['position' => 0, 'key' => 'query', 'default' => null, 'required' => true], ['position' => 1, 'key' => 'warn', 'default' => null, 'required' => false], ['position' => 2, 'key' => 'crit', 'default' => null, 'required' => false]], 'capability_flags' => ['supports_thresholds'], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'nagios', 'display_name' => 'Nagios (remote)', 'description' => 'Reads a remote Nagios status file. Use for distributed or redundant Nagios monitoring.', 'check_command' => 'check_nagios', 'args_schema' => [['position' => 0, 'key' => 'path', 'default' => '/usr/local/nagios/var/status.dat', 'required' => false]], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'mrtg', 'display_name' => 'MRTG', 'description' => 'Checks MRTG log data. Use when traffic data is collected by MRTG.', 'check_command' => 'check_mrtg', 'args_schema' => [['position' => 0, 'key' => 'path', 'default' => null, 'required' => true]], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'mrtgtraf', 'display_name' => 'MRTG Traffic', 'description' => 'Checks MRTG traffic against warn/crit. Use to alert on bandwidth or traffic anomalies.', 'check_command' => 'check_mrtgtraf', 'args_schema' => [['position' => 0, 'key' => 'path', 'default' => null, 'required' => true], ['position' => 1, 'key' => 'warn', 'default' => null, 'required' => false], ['position' => 2, 'key' => 'crit', 'default' => null, 'required' => false]], 'capability_flags' => ['supports_thresholds'], 'status' => 'disabled'],
        ];
    }
}
