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
                'engine' => 'nagios',
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
                'engine' => 'nagios',
                'service_key' => 'http',
                'display_name' => 'HTTP',
                'description' => 'Performs an HTTP GET to the given URL and checks the response status. Use this to monitor web apps, APIs, or any HTTP service. You can set path, port, and expected status code.',
                'check_command' => 'check_http',
                'args_schema' => [
                    ['position' => 0, 'key' => 'path', 'default' => '/', 'required' => false],
                    ['position' => 1, 'key' => 'port', 'default' => 80, 'required' => false],
                    ['position' => 2, 'key' => 'expect', 'default' => null, 'required' => false],
                ],
                'capability_flags' => ['supports_urls', 'supports_ports'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
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
                'engine' => 'nagios',
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
                'engine' => 'nagios',
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
