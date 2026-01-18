<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            if (!Schema::hasColumn('modules', 'key')) {
                $table->string('key')->unique()->nullable()->after('id');
            }
        });

        // Backfill keys for existing modules
        $this->backfillModuleKeys();
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn('key');
        });
    }

    private function backfillModuleKeys(): void
    {
        $moduleKeys = [
            'ShieldCore' => 'shieldcore',
            'ShieldObserve' => 'shieldobserve',
            'ShieldInventory' => 'shieldinventory',
            'ShieldRespond' => 'shieldrespond',
            'ShieldSecure' => 'shieldsecure',
            'ShieldNotify' => 'shieldnotify',
            'ShieldVoice' => 'shieldvoice',
            'ShieldKnowledge' => 'shieldknowledge',
            'ShieldAutomate' => 'shieldautomate',
            'ShieldBalance' => 'shieldbalance',
            'ShieldDesk' => 'shielddesk',
        ];

        foreach ($moduleKeys as $name => $key) {
            \DB::table('modules')
                ->where('name', $name)
                ->update(['key' => $key]);
        }
    }
};
