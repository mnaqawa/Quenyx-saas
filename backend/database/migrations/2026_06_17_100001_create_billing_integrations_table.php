<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('projects')->cascadeOnDelete();
            $table->string('provider_type', 32);
            $table->string('status', 32)->default('not_connected');
            $table->json('config')->nullable();
            $table->string('billing_contact')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'provider_type']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_integrations');
    }
};
