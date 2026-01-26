<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observe_meta', function (Blueprint $table) {
            $table->dateTime('last_publish_at')->nullable()->after('last_poll_at');
            $table->boolean('last_publish_success')->nullable()->after('last_publish_at');
            $table->text('last_publish_error')->nullable()->after('last_publish_success');
        });
    }

    public function down(): void
    {
        Schema::table('observe_meta', function (Blueprint $table) {
            $table->dropColumn(['last_publish_at', 'last_publish_success', 'last_publish_error']);
        });
    }
};
