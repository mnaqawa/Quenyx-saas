<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            if (! Schema::hasColumn('agents', 'lifecycle_status')) {
                $table->string('lifecycle_status', 40)->default('online')->after('status');
            }
            if (! Schema::hasColumn('agents', 'policy_version')) {
                $table->string('policy_version', 40)->nullable()->after('agent_version');
            }
            if (! Schema::hasColumn('agents', 'platform_version')) {
                $table->string('platform_version', 40)->nullable()->after('policy_version');
            }
            if (! Schema::hasColumn('agents', 'policy_status')) {
                $table->string('policy_status', 40)->default('up_to_date')->after('platform_version');
            }
            if (! Schema::hasColumn('agents', 'capability_hash')) {
                $table->string('capability_hash', 64)->nullable()->after('policy_status');
            }
            if (! Schema::hasColumn('agents', 'plugin_versions')) {
                $table->json('plugin_versions')->nullable()->after('capability_hash');
            }
            if (! Schema::hasColumn('agents', 'preferred_gateway_id')) {
                $table->uuid('preferred_gateway_id')->nullable()->after('plugin_versions');
            }
            if (! Schema::hasColumn('agents', 'last_error')) {
                $table->text('last_error')->nullable()->after('last_seen_at');
            }
            if (! Schema::hasColumn('agents', 'heartbeat_count')) {
                $table->unsignedBigInteger('heartbeat_count')->default(0)->after('last_error');
            }
            if (! Schema::hasColumn('agents', 'bytes_sent')) {
                $table->unsignedBigInteger('bytes_sent')->default(0)->after('heartbeat_count');
            }
            if (! Schema::hasColumn('agents', 'bytes_received')) {
                $table->unsignedBigInteger('bytes_received')->default(0)->after('bytes_sent');
            }
        });

        if (! Schema::hasTable('agent_gateways')) {
            Schema::create('agent_gateways', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('workspace_id')->nullable()->index();
                $table->string('name', 120);
                $table->string('region', 60)->nullable();
                $table->string('endpoint_url', 500);
                $table->string('version', 40)->nullable();
                $table->string('health_status', 30)->default('unknown');
                $table->unsignedInteger('capacity')->default(1000);
                $table->unsignedInteger('connected_agents')->default(0);
                $table->boolean('is_primary')->default(false);
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_managed_resources')) {
            Schema::create('agent_managed_resources', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_id')->index();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('resource_type', 60);
                $table->string('display_name', 255);
                $table->uuid('parent_resource_id')->nullable()->index();
                $table->string('lifecycle_status', 40)->default('active');
                $table->string('health_status', 30)->default('unknown');
                $table->timestamp('last_seen_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('platform_assets')) {
            Schema::create('platform_assets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->uuid('agent_id')->nullable()->index();
                $table->uuid('managed_resource_id')->nullable()->index();
                $table->unsignedBigInteger('monitoring_target_id')->nullable()->index();
                $table->string('name', 255);
                $table->string('asset_type', 60);
                $table->string('lifecycle_status', 40)->default('active');
                $table->string('health_status', 30)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
                $table->foreign('managed_resource_id')->references('id')->on('agent_managed_resources')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('agent_plugins')) {
            Schema::create('agent_plugins', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_id')->index();
                $table->string('plugin_key', 80);
                $table->string('name', 120);
                $table->string('version', 40)->nullable();
                $table->string('vendor', 120)->nullable();
                $table->text('description')->nullable();
                $table->string('status', 30)->default('active');
                $table->string('health_status', 30)->default('unknown');
                $table->timestamp('last_execution_at')->nullable();
                $table->unsignedInteger('error_count')->default(0);
                $table->json('required_permissions')->nullable();
                $table->json('dependencies')->nullable();
                $table->string('configuration_version', 40)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['agent_id', 'plugin_key']);
                $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            });
        }

        $this->seedDefaultGateway();
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_plugins');
        Schema::dropIfExists('platform_assets');
        Schema::dropIfExists('agent_managed_resources');
        Schema::dropIfExists('agent_gateways');

        Schema::table('agents', function (Blueprint $table) {
            foreach ([
                'lifecycle_status', 'policy_version', 'platform_version', 'policy_status',
                'capability_hash', 'plugin_versions', 'preferred_gateway_id',
                'last_error', 'heartbeat_count', 'bytes_sent', 'bytes_received',
            ] as $col) {
                if (Schema::hasColumn('agents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function seedDefaultGateway(): void
    {
        $url = config('agent.gateway_url', env('AGENT_GATEWAY_URL', 'https://cloud.quenyx.com:9444'));
        $exists = DB::table('agent_gateways')->where('is_primary', true)->exists();
        if ($exists) {
            return;
        }

        DB::table('agent_gateways')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'name' => 'Default Agent Gateway',
            'region' => config('agent.gateway_region', 'default'),
            'endpoint_url' => $url,
            'version' => config('agent.gateway_version', '1.0.0'),
            'health_status' => 'healthy',
            'capacity' => (int) config('agent.gateway_capacity', 5000),
            'connected_agents' => 0,
            'is_primary' => true,
            'last_heartbeat_at' => now(),
            'metadata' => json_encode(['source' => 'migration_seed']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
