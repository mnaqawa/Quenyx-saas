<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_port_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('observe_targets_hosts')->onDelete('cascade');
            $table->string('status', 20)->default('pending'); // pending, running, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->integer('open_ports_count')->nullable();
            $table->timestamps();

            $table->index(['host_id', 'status']);
        });

        Schema::create('host_port_scan_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained('host_port_scans')->onDelete('cascade');
            $table->unsignedSmallInteger('port');
            $table->string('protocol', 10)->default('tcp');
            $table->string('state', 20)->default('open'); // open, closed, filtered, etc.
            $table->string('service', 100)->nullable();
            $table->string('version', 255)->nullable();
            $table->timestamps();

            $table->unique(['scan_id', 'port', 'protocol'], 'host_port_scan_results_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_port_scan_results');
        Schema::dropIfExists('host_port_scans');
    }
};
