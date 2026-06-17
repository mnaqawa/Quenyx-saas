<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('profile_key', 64);
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['workspace_id', 'profile_key']);
        });

        Schema::create('monitoring_profile_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('monitoring_profiles')->cascadeOnDelete();
            $table->string('service_key', 64);
            $table->string('service_name', 120);
            $table->json('check_args')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['profile_id', 'service_key']);
        });

        $now = now();
        $profileId = DB::table('monitoring_profiles')->insertGetId([
            'workspace_id' => null,
            'profile_key' => 'default_infrastructure',
            'name' => 'Default Infrastructure Monitoring',
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $checks = [
            ['service_key' => 'ping', 'service_name' => 'ping', 'check_args' => json_encode(['crit_pl_pct' => 100]), 'sort_order' => 0],
            ['service_key' => 'cpu', 'service_name' => 'cpu', 'check_args' => json_encode(['warn_pct' => 80, 'crit_pct' => 90]), 'sort_order' => 1],
            ['service_key' => 'memory', 'service_name' => 'memory', 'check_args' => json_encode(['warn_pct' => 80, 'crit_pct' => 90]), 'sort_order' => 2],
            ['service_key' => 'disk', 'service_name' => 'disk', 'check_args' => json_encode(['mount' => '/', 'warn_pct' => 20, 'crit_pct' => 10]), 'sort_order' => 3],
            ['service_key' => 'load', 'service_name' => 'load', 'check_args' => json_encode([]), 'sort_order' => 4],
        ];

        foreach ($checks as $check) {
            DB::table('monitoring_profile_checks')->insert([
                'profile_id' => $profileId,
                'service_key' => $check['service_key'],
                'service_name' => $check['service_name'],
                'check_args' => $check['check_args'],
                'enabled' => true,
                'sort_order' => $check['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_profile_checks');
        Schema::dropIfExists('monitoring_profiles');
    }
};
