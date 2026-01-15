<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('github_pat')->nullable();
            $table->string('slack_webhook_url')->nullable();
            $table->string('primary_webhook_url')->nullable();
            $table->string('backup_webhook_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_configurations');
    }
};
