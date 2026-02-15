<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_enrollment_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('projects')->onDelete('cascade');
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique(); // SHA-256 of token for lookup
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'token_hash']);
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('enrollment_token_id')->nullable()->constrained('agent_enrollment_tokens')->nullOnDelete();
            $table->string('hostname');
            $table->string('os')->nullable();
            $table->string('arch')->nullable();
            $table->string('agent_version')->nullable();
            $table->string('primary_protocol')->default('http_api'); // http_api, nrpe, snmp
            $table->json('enabled_protocols')->nullable(); // ['http_api','nrpe'] etc
            $table->json('permissions')->nullable(); // checklist: system_metrics, inventory, network, etc
            $table->string('agent_secret_hash', 255);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status')->default('online'); // online, offline, stale, error
            $table->timestamp('enrolled_at');
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
            $table->index('last_seen_at');
        });

        Schema::create('agent_metrics', function (Blueprint $table) {
            $table->id();
            $table->uuid('agent_id');
            $table->timestamp('collected_at');
            $table->json('payload');
            $table->timestamps();
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            $table->index(['agent_id', 'collected_at']);
        });

        Schema::create('agent_inventories', function (Blueprint $table) {
            $table->id();
            $table->uuid('agent_id');
            $table->timestamp('collected_at');
            $table->json('payload');
            $table->timestamps();
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            $table->unique(['agent_id', 'collected_at'], 'agent_inventories_agent_collected_unique');
        });

        Schema::table('observe_targets_hosts', function (Blueprint $table) {
            $table->uuid('agent_id')->nullable()->after('address');
            $table->string('source')->default('manual')->after('agent_id'); // manual, agent
        });

        Schema::table('observe_targets_hosts', function (Blueprint $table) {
            $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('observe_targets_hosts', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropColumn(['agent_id', 'source']);
        });
        Schema::dropIfExists('agent_inventories');
        Schema::dropIfExists('agent_metrics');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('agent_enrollment_tokens');
    }
};
