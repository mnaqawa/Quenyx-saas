<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observe_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('projects')->onDelete('cascade');
            $table->string('engine_key', 50)->default('native');
            $table->dateTime('last_poll_at')->nullable();
            $table->json('service_totals_json')->nullable(); // Store totals as JSON
            $table->text('error')->nullable(); // Store error message if poll failed
            $table->timestamps();

            // Unique: one meta record per workspace/engine
            $table->unique(['workspace_id', 'engine_key'], 'observe_meta_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observe_meta');
    }
};
