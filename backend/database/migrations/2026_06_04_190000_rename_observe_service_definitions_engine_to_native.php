<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observe_service_definitions')) {
            return;
        }

        $rows = DB::table('observe_service_definitions')
            ->where('engine', 'nagios')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $nativeExists = DB::table('observe_service_definitions')
                ->where('engine', 'native')
                ->where('service_key', $row->service_key)
                ->exists();

            if ($nativeExists) {
                DB::table('observe_service_definitions')->where('id', $row->id)->delete();
                continue;
            }

            DB::table('observe_service_definitions')
                ->where('id', $row->id)
                ->update(['engine' => 'native']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('observe_service_definitions')) {
            return;
        }

        $rows = DB::table('observe_service_definitions')
            ->where('engine', 'native')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $nagiosExists = DB::table('observe_service_definitions')
                ->where('engine', 'nagios')
                ->where('service_key', $row->service_key)
                ->exists();

            if ($nagiosExists) {
                DB::table('observe_service_definitions')->where('id', $row->id)->delete();
                continue;
            }

            DB::table('observe_service_definitions')
                ->where('id', $row->id)
                ->update(['engine' => 'nagios']);
        }
    }
};
