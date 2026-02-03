<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observe_services', function (Blueprint $table) {
            $table->dateTime('next_check_at')->nullable()->after('last_check_at');
            $table->unsignedTinyInteger('current_attempt')->nullable()->after('attempt');
            $table->unsignedTinyInteger('max_attempts')->nullable()->after('current_attempt');
            $table->string('state_type', 20)->nullable()->after('state'); // soft|hard
            $table->text('plugin_output')->nullable()->after('output');
            $table->text('long_plugin_output')->nullable()->after('plugin_output');
            $table->string('check_command', 255)->nullable()->after('long_plugin_output');
            $table->decimal('check_latency_sec', 10, 3)->nullable()->after('check_command');
            $table->decimal('execution_time_sec', 10, 3)->nullable()->after('check_latency_sec');
            $table->dateTime('last_state_change_at')->nullable()->after('execution_time_sec');
        });
    }

    public function down(): void
    {
        Schema::table('observe_services', function (Blueprint $table) {
            $table->dropColumn([
                'next_check_at',
                'current_attempt',
                'max_attempts',
                'state_type',
                'plugin_output',
                'long_plugin_output',
                'check_command',
                'check_latency_sec',
                'execution_time_sec',
                'last_state_change_at',
            ]);
        });
    }
};
