<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'preferences')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'preferences')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
