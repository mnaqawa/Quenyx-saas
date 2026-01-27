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
                'check_command' => 'check_tcp',
                'args_schema' => [
                    ['position' => 0, 'key' => 'port', 'default' => 80, 'required' => true],
                ],
                'capability_flags' => ['supports_ports'],
                'status' => 'active',
            ],
            [
                'engine' => 'nagios',
                'service_key' => 'custom',
                'display_name' => 'Custom',
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
