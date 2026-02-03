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
            // ---- System metrics (most common with NRPE) ----
            [
                'engine' => 'nagios',
                'service_key' => 'disk',
                'display_name' => 'Disk Space',
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
                'service_key' => 'load',
                'display_name' => 'Load',
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
                'check_command' => 'check_ntp_peer',
                'args_schema' => [],
                'capability_flags' => [],
                'status' => 'disabled',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'ssh',
                'display_name' => 'SSH',
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
            ['engine' => 'nagios', 'service_key' => 'dig', 'display_name' => 'DNS (dig)', 'check_command' => 'check_dig', 'args_schema' => [['position' => 0, 'key' => 'server', 'default' => null, 'required' => false]], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'dhcp', 'display_name' => 'DHCP', 'check_command' => 'check_dhcp', 'args_schema' => [], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'mailq', 'display_name' => 'Mail Queue', 'check_command' => 'check_mailq', 'args_schema' => [['position' => 0, 'key' => 'warn', 'default' => null, 'required' => false], ['position' => 1, 'key' => 'crit', 'default' => null, 'required' => false]], 'capability_flags' => ['supports_thresholds'], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'sensors', 'display_name' => 'Hardware Sensors', 'check_command' => 'check_sensors', 'args_schema' => [], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'snmp', 'display_name' => 'SNMP', 'check_command' => 'check_snmp', 'args_schema' => [['position' => 0, 'key' => 'oid', 'default' => null, 'required' => false]], 'capability_flags' => [], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'udp', 'display_name' => 'UDP Port', 'check_command' => 'check_udp', 'args_schema' => [['position' => 0, 'key' => 'port', 'default' => null, 'required' => true]], 'capability_flags' => ['supports_ports'], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'time', 'display_name' => 'Time (TCP)', 'check_command' => 'check_time', 'args_schema' => [['position' => 0, 'key' => 'port', 'default' => 37, 'required' => false]], 'capability_flags' => ['supports_ports'], 'status' => 'disabled'],
            ['engine' => 'nagios', 'service_key' => 'by_ssh', 'display_name' => 'By SSH (remote check)', 'check_command' => 'check_by_ssh', 'args_schema' => [['position' => 0, 'key' => 'command', 'default' => null, 'required' => true]], 'capability_flags' => [], 'status' => 'disabled'],
        ];
    }
}
