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
            if (! Schema::hasColumn('agents', 'update_channel')) {
                $table->string('update_channel', 30)->default('stable')->after('agent_version');
            }
            if (! Schema::hasColumn('agents', 'update_status')) {
                $table->string('update_status', 40)->nullable()->after('update_channel');
            }
            if (! Schema::hasColumn('agents', 'update_progress')) {
                $table->unsignedTinyInteger('update_progress')->nullable()->after('update_status');
            }
            if (! Schema::hasColumn('agents', 'config_version')) {
                $table->string('config_version', 40)->nullable()->after('policy_version');
            }
            if (! Schema::hasColumn('agents', 'health_score')) {
                $table->unsignedTinyInteger('health_score')->nullable()->after('lifecycle_status');
            }
            if (! Schema::hasColumn('agents', 'health_level')) {
                $table->string('health_level', 20)->nullable()->after('health_score');
            }
            if (! Schema::hasColumn('agents', 'health_breakdown')) {
                $table->json('health_breakdown')->nullable()->after('health_level');
            }
            if (! Schema::hasColumn('agents', 'queue_stats')) {
                $table->json('queue_stats')->nullable()->after('bytes_received');
            }
            if (! Schema::hasColumn('agents', 'certificate_fingerprint')) {
                $table->string('certificate_fingerprint', 128)->nullable()->after('queue_stats');
            }
        });

        if (! Schema::hasTable('agent_releases')) {
            Schema::create('agent_releases', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('version', 40);
                $table->string('channel', 30)->default('stable');
                $table->string('platform', 30)->default('linux');
                $table->string('arch', 30)->default('amd64');
                $table->string('download_url', 500);
                $table->string('checksum_sha256', 64);
                $table->text('signature')->nullable();
                $table->string('rollback_version', 40)->nullable();
                $table->boolean('mandatory')->default(false);
                $table->boolean('is_latest')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['version', 'channel', 'platform', 'arch'], 'agent_releases_unique');
                $table->index(['channel', 'is_latest']);
            });
        }

        if (! Schema::hasTable('agent_update_assignments')) {
            Schema::create('agent_update_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_id')->index();
                $table->uuid('release_id')->index();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('status', 40)->default('pending');
                $table->unsignedTinyInteger('progress')->default(0);
                $table->string('result', 40)->nullable();
                $table->boolean('approved')->default(false);
                $table->boolean('rollback_allowed')->default(true);
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('maintenance_window_start')->nullable();
                $table->timestamp('maintenance_window_end')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
                $table->foreign('release_id')->references('id')->on('agent_releases')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('agent_update_history')) {
            Schema::create('agent_update_history', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_id')->index();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('from_version', 40)->nullable();
                $table->string('to_version', 40)->nullable();
                $table->string('channel', 30)->nullable();
                $table->string('status', 40);
                $table->string('result', 40)->nullable();
                $table->boolean('rollback')->default(false);
                $table->text('detail')->nullable();
                $table->timestamp('recorded_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_certificates')) {
            Schema::create('agent_certificates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_id')->index();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('status', 30)->default('pending');
                $table->string('issuer', 255)->nullable();
                $table->string('fingerprint', 128)->nullable();
                $table->text('csr_pem')->nullable();
                $table->text('certificate_pem')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('rotation_due_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('revocation_reason', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('agent_gateway_certificates')) {
            Schema::create('agent_gateway_certificates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('gateway_id')->index();
                $table->string('status', 30)->default('active');
                $table->string('issuer', 255)->nullable();
                $table->string('fingerprint', 128)->nullable();
                $table->text('certificate_pem')->nullable();
                $table->text('trust_chain_pem')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->foreign('gateway_id')->references('id')->on('agent_gateways')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('agent_configuration_revisions')) {
            Schema::create('agent_configuration_revisions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('workspace_id')->nullable()->index();
                $table->string('version', 40);
                $table->string('status', 30)->default('active');
                $table->json('settings');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('rollback_of_version', 40)->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'version'], 'agent_config_workspace_version');
            });
        }

        if (! Schema::hasTable('agent_offline_events')) {
            Schema::create('agent_offline_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_id')->index();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('event_type', 40);
                $table->string('dedup_key', 128);
                $table->json('payload');
                $table->timestamp('event_at');
                $table->timestamp('ingested_at')->nullable();
                $table->string('source', 30)->default('replay');
                $table->timestamps();

                $table->unique(['agent_id', 'dedup_key'], 'agent_offline_events_dedup');
            });
        }

        if (! Schema::hasTable('agent_diagnostics_bundles')) {
            Schema::create('agent_diagnostics_bundles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_id')->index();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('source', 30)->default('agent');
                $table->json('summary')->nullable();
                $table->longText('bundle_json')->nullable();
                $table->string('storage_path', 500)->nullable();
                $table->unsignedInteger('size_bytes')->default(0);
                $table->timestamp('generated_at');
                $table->timestamps();

                $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('agent_update_campaigns')) {
            Schema::create('agent_update_campaigns', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->uuid('release_id')->index();
                $table->string('name', 120);
                $table->string('channel', 30)->default('stable');
                $table->string('status', 30)->default('draft');
                $table->boolean('mandatory')->default(false);
                $table->boolean('require_approval')->default(true);
                $table->timestamp('maintenance_window_start')->nullable();
                $table->timestamp('maintenance_window_end')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->json('target_filters')->nullable();
                $table->timestamps();

                $table->foreign('release_id')->references('id')->on('agent_releases')->cascadeOnDelete();
            });
        }

        $this->seedDefaultReleases();
        $this->seedDefaultConfiguration();
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_update_campaigns');
        Schema::dropIfExists('agent_diagnostics_bundles');
        Schema::dropIfExists('agent_offline_events');
        Schema::dropIfExists('agent_configuration_revisions');
        Schema::dropIfExists('agent_gateway_certificates');
        Schema::dropIfExists('agent_certificates');
        Schema::dropIfExists('agent_update_history');
        Schema::dropIfExists('agent_update_assignments');
        Schema::dropIfExists('agent_releases');

        Schema::table('agents', function (Blueprint $table) {
            foreach ([
                'update_channel', 'update_status', 'update_progress', 'config_version',
                'health_score', 'health_level', 'health_breakdown', 'queue_stats', 'certificate_fingerprint',
            ] as $col) {
                if (Schema::hasColumn('agents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function seedDefaultReleases(): void
    {
        if (DB::table('agent_releases')->exists()) {
            return;
        }

        $latest = (string) config('agent.policy.latest_agent_version', '1.0.0');
        $baseUrl = rtrim((string) config('app.url', 'https://cloud.quenyx.com'), '/');

        foreach (['linux', 'windows', 'darwin'] as $platform) {
            foreach (['stable', 'beta'] as $channel) {
                DB::table('agent_releases')->insert([
                    'id' => (string) Str::uuid(),
                    'version' => $latest,
                    'channel' => $channel,
                    'platform' => $platform,
                    'arch' => 'amd64',
                    'download_url' => "{$baseUrl}/api/agents/download/{$platform}",
                    'checksum_sha256' => hash('sha256', "quenyx-agent-{$latest}-{$platform}-{$channel}"),
                    'signature' => null,
                    'rollback_version' => '1.0.0',
                    'mandatory' => false,
                    'is_latest' => $channel === 'stable',
                    'published_at' => now(),
                    'metadata' => json_encode(['seeded' => true]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedDefaultConfiguration(): void
    {
        if (DB::table('agent_configuration_revisions')->whereNull('workspace_id')->exists()) {
            return;
        }

        DB::table('agent_configuration_revisions')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'version' => (string) config('agent.configuration.default_version', '1.0.0'),
            'status' => 'active',
            'settings' => json_encode(config('agent.configuration.defaults', [])),
            'created_by' => null,
            'rollback_of_version' => null,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
