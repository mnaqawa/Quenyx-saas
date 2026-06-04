<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameObserveServices('nagios', 'native');
        $this->renameObserveMeta('nagios', 'native');
    }

    public function down(): void
    {
        $this->renameObserveServices('native', 'nagios');
        $this->renameObserveMeta('native', 'nagios');
    }

    private function renameObserveServices(string $from, string $to): void
    {
        if (! Schema::hasTable('observe_services')) {
            return;
        }

        $rows = DB::table('observe_services')
            ->where('engine_key', $from)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $duplicate = DB::table('observe_services')
                ->where('workspace_id', $row->workspace_id)
                ->where('engine_key', $to)
                ->where('engine_service_key', $row->engine_service_key)
                ->exists();

            if ($duplicate) {
                DB::table('observe_services')->where('id', $row->id)->delete();
                continue;
            }

            DB::table('observe_services')->where('id', $row->id)->update(['engine_key' => $to]);
        }
    }

    private function renameObserveMeta(string $from, string $to): void
    {
        if (! Schema::hasTable('observe_meta')) {
            return;
        }

        $rows = DB::table('observe_meta')
            ->where('engine_key', $from)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $duplicate = DB::table('observe_meta')
                ->where('workspace_id', $row->workspace_id)
                ->where('engine_key', $to)
                ->exists();

            if ($duplicate) {
                DB::table('observe_meta')->where('id', $row->id)->delete();
                continue;
            }

            DB::table('observe_meta')->where('id', $row->id)->update(['engine_key' => $to]);
        }
    }
};
