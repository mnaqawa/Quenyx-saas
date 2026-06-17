<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observe_report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('projects')->cascadeOnDelete();
            $table->string('export_type', 64);
            $table->string('format', 16)->default('json');
            $table->string('title', 200);
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observe_report_exports');
    }
};
