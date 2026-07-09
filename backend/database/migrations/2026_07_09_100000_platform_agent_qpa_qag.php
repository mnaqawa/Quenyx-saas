<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->json('capabilities')->nullable()->after('permissions');
            $table->json('enabled_modules')->nullable()->after('capabilities');
            $table->json('private_ips')->nullable()->after('enabled_modules');
            $table->string('public_ip', 45)->nullable()->after('private_ips');
            $table->string('observed_source_ip', 45)->nullable()->after('public_ip');
            $table->json('interfaces')->nullable()->after('observed_source_ip');
            $table->string('region', 120)->nullable()->after('interfaces');
            $table->string('cloud_provider', 64)->nullable()->after('region');
            $table->string('availability_zone', 64)->nullable()->after('cloud_provider');
            $table->boolean('nat_detected')->default(false)->after('availability_zone');
            $table->boolean('vpn_detected')->default(false)->after('nat_detected');
            $table->uuid('workspace_uuid')->nullable()->after('workspace_id');
        });

        if (Schema::hasTable('observe_targets_services') && ! Schema::hasColumn('observe_targets_services', 'check_source')) {
            Schema::table('observe_targets_services', function (Blueprint $table) {
                $table->string('check_source', 32)->default('pull')->after('check_command');
            });
        }

        // Remediate existing agent-enrolled hosts: switch from SSH pull plugins to platform_agent telemetry
        if (Schema::hasTable('observe_targets_hosts') && Schema::hasColumn('observe_targets_services', 'check_source')) {
            $agentHostIds = \Illuminate\Support\Facades\DB::table('observe_targets_hosts')
                ->whereNotNull('agent_id')
                ->pluck('id');
            if ($agentHostIds->isNotEmpty()) {
                \Illuminate\Support\Facades\DB::table('observe_targets_services')
                    ->whereIn('host_id', $agentHostIds)
                    ->whereIn('service_key', ['cpu', 'memory', 'disk', 'load'])
                    ->update([
                        'check_source' => 'platform_agent',
                        'check_command' => 'platform_agent_telemetry',
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('observe_targets_services') && Schema::hasColumn('observe_targets_services', 'check_source')) {
            Schema::table('observe_targets_services', function (Blueprint $table) {
                $table->dropColumn('check_source');
            });
        }

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'capabilities',
                'enabled_modules',
                'private_ips',
                'public_ip',
                'observed_source_ip',
                'interfaces',
                'region',
                'cloud_provider',
                'availability_zone',
                'nat_detected',
                'vpn_detected',
                'workspace_uuid',
            ]);
        });
    }
};
