<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('host_port_scans')) {
            return;
        }

        Schema::table('host_port_scans', function (Blueprint $table) {
            if (! Schema::hasColumn('host_port_scans', 'target_mode')) {
                // public = black-box (scan public_ip from Quenyx), private = white-box (scan address)
                $table->string('target_mode', 16)->default('auto')->after('host_id');
            }
            if (! Schema::hasColumn('host_port_scans', 'scanned_address')) {
                $table->string('scanned_address', 255)->nullable()->after('target_mode');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('host_port_scans')) {
            return;
        }

        Schema::table('host_port_scans', function (Blueprint $table) {
            if (Schema::hasColumn('host_port_scans', 'scanned_address')) {
                $table->dropColumn('scanned_address');
            }
            if (Schema::hasColumn('host_port_scans', 'target_mode')) {
                $table->dropColumn('target_mode');
            }
        });
    }
};
