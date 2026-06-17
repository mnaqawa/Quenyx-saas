<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('observe_alert_events')) {
            Schema::table('observe_alert_events', function (Blueprint $table) {
                if (! Schema::hasColumn('observe_alert_events', 'target_host_id')) {
                    $table->unsignedBigInteger('target_host_id')->nullable()->after('alert_rule_id');
                }
                if (! Schema::hasColumn('observe_alert_events', 'target_service_key')) {
                    $table->string('target_service_key', 64)->nullable()->after('target_host_id');
                }
                if (! Schema::hasColumn('observe_alert_events', 'host_name')) {
                    $table->string('host_name', 255)->nullable()->after('target_service_key');
                }
                if (! Schema::hasColumn('observe_alert_events', 'service_name')) {
                    $table->string('service_name', 255)->nullable()->after('host_name');
                }
                if (! Schema::hasColumn('observe_alert_events', 'opened_at')) {
                    $table->timestamp('opened_at')->nullable()->after('message');
                }
                if (! Schema::hasColumn('observe_alert_events', 'last_seen_at')) {
                    $table->timestamp('last_seen_at')->nullable()->after('opened_at');
                }
                if (! Schema::hasColumn('observe_alert_events', 'occurrence_count')) {
                    $table->unsignedInteger('occurrence_count')->default(1)->after('last_seen_at');
                }
            });

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                DB::statement(
                    "ALTER TABLE observe_alert_events MODIFY COLUMN status ENUM('open', 'active', 'acknowledged', 'resolved') NOT NULL DEFAULT 'open'"
                );
            }

            DB::table('observe_alert_events')->where('status', 'active')->update(['status' => 'open']);

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                DB::statement(
                    "ALTER TABLE observe_alert_events MODIFY COLUMN status ENUM('open', 'acknowledged', 'resolved') NOT NULL DEFAULT 'open'"
                );
            }

            DB::table('observe_alert_events')
                ->whereNull('opened_at')
                ->update(['opened_at' => DB::raw('triggered_at')]);

            Schema::table('observe_alert_events', function (Blueprint $table) {
                $table->index(['workspace_id', 'alert_rule_id', 'status'], 'alert_events_ws_rule_status_idx');
            });
        }

        if (! Schema::hasTable('observe_alert_eval_states')) {
            Schema::create('observe_alert_eval_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('alert_rule_id')->constrained('observe_alert_rules')->cascadeOnDelete();
                $table->unsignedBigInteger('target_host_id')->nullable();
                $table->string('target_service_key', 64)->nullable();
                $table->string('host_name', 255)->nullable();
                $table->string('service_name', 255)->nullable();
                $table->timestamp('condition_met_since')->nullable();
                $table->timestamp('last_evaluated_at')->nullable();
                $table->decimal('last_value', 12, 4)->nullable();
                $table->timestamps();

                $table->unique(
                    ['workspace_id', 'alert_rule_id', 'target_host_id', 'target_service_key', 'host_name', 'service_name'],
                    'alert_eval_state_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('observe_alert_eval_states');

        if (Schema::hasTable('observe_alert_events')) {
            Schema::table('observe_alert_events', function (Blueprint $table) {
                $table->dropIndex('alert_events_ws_rule_status_idx');
                $table->dropColumn([
                    'target_host_id',
                    'target_service_key',
                    'host_name',
                    'service_name',
                    'opened_at',
                    'last_seen_at',
                    'occurrence_count',
                ]);
            });
            DB::table('observe_alert_events')->where('status', 'open')->update(['status' => 'active']);
        }
    }
};
