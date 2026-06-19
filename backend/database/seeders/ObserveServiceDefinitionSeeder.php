<?php

namespace Database\Seeders;

use App\Models\ObserveServiceDefinition;
use Illuminate\Database\Seeder;

/**
 * Idempotent seeder for observe_service_definitions.
 * MVP scope: ping, http, tcp_port, custom (disabled).
 * args_schema is an ordered list; no engine syntax; custom is disabled by default.
 */
class ObserveServiceDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'engine' => 'native',
                'service_key' => 'ping',
                'display_name' => 'Ping',
                'description' => 'Checks host reachability and latency using ICMP. Alerts on packet loss or high round-trip time. Use this to ensure the host is online and responsive.',
                'check_command' => 'check_ping',
                'args_schema' => [
                    ['position' => 0, 'key' => 'warn_rta_ms', 'default' => 100, 'required' => false],
                    ['position' => 1, 'key' => 'warn_pl_pct', 'default' => 5, 'required' => false],
                    ['position' => 2, 'key' => 'crit_rta_ms', 'default' => 500, 'required' => false],
                    ['position' => 3, 'key' => 'crit_pl_pct', 'default' => 20, 'required' => false],
                ],
                'capability_flags' => ['supports_thresholds'],
                'status' => 'active',
            ],
            [
                'engine' => 'native',
                'service_key' => 'http',
                'display_name' => 'HTTP',
                'description' => 'Performs an HTTP GET and checks the response status. Set a full URL to monitor external sites, or use path/port on the target host. SSL/vhost checks use the URL or hostname field.',
                'check_command' => 'check_http',
                'args_schema' => [
                    ['position' => 0, 'key' => 'url', 'type' => 'string', 'default' => null, 'required' => false, 'help' => 'Full URL e.g. https://cloud.example.com/ (optional — overrides host path)'],
                    ['position' => 1, 'key' => 'hostname', 'type' => 'string', 'default' => null, 'required' => false, 'help' => 'Hostname for vhost/SNI when not using full URL'],
                    ['position' => 2, 'key' => 'path', 'type' => 'string', 'default' => '/', 'required' => false, 'help' => 'Request path when URL is empty (default /)'],
                    ['position' => 3, 'key' => 'port', 'type' => 'int', 'default' => null, 'required' => false, 'help' => 'Port (optional — 80 HTTP, 443 HTTPS)'],
                    ['position' => 4, 'key' => 'use_ssl', 'type' => 'bool', 'default' => false, 'required' => false, 'help' => 'Use HTTPS when building URL from host + path'],
                    ['position' => 5, 'key' => 'expect', 'type' => 'int', 'default' => 200, 'required' => false, 'help' => 'Expected HTTP status code'],
                ],
                'capability_flags' => ['supports_urls', 'supports_ports'],
                'status' => 'active',
            ],
            [
                'engine' => 'native',
                'service_key' => 'tcp_port',
                'display_name' => 'TCP Port',
                'description' => 'Checks if a TCP port is open and accepting connections. Ideal for databases, SSH, mail, or any service that listens on a port.',
                'check_command' => 'check_tcp',
                'args_schema' => [
                    ['position' => 0, 'key' => 'port', 'default' => 80, 'required' => true],
                ],
                'capability_flags' => ['supports_ports'],
                'status' => 'active',
            ],
            [
                'engine' => 'native',
                'service_key' => 'plugin',
                'display_name' => 'Custom Script',
                'description' => 'Runs your own monitoring script by name. Place the script (e.g. my_check.sh or my_check.php) in the observe plugins directory and enter its name here. The script receives the host address and your args via environment variables and must exit 0=OK, 1=Warning, 2=Critical, 3=Unknown.',
                'check_command' => 'check_plugin',
                'args_schema' => [
                    ['position' => 0, 'key' => 'plugin', 'default' => null, 'required' => true],
                ],
                'capability_flags' => ['supports_custom_scripts'],
                'status' => 'active',
            ],
            [
                'engine' => 'native',
                'service_key' => 'custom',
                'display_name' => 'Custom',
                'description' => null,
                'check_command' => '',
                'args_schema' => [],
                'capability_flags' => [],
                'status' => 'disabled', // Not enabled by default
            ],
        ];

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
}
