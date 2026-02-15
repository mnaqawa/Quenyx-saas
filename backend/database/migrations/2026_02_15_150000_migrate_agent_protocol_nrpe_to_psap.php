<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('agents')) {
            return;
        }
        DB::table('agents')
            ->where('primary_protocol', 'nrpe')
            ->update(['primary_protocol' => 'psap']);
    }

    public function down(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('agents')) {
            return;
        }
        DB::table('agents')
            ->where('primary_protocol', 'psap')
            ->update(['primary_protocol' => 'nrpe']);
    }
};
