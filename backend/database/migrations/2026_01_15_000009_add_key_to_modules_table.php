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
            // Check if a module with this key already exists
            $existingWithKey = \DB::table('modules')
                ->where('key', $key)
                ->first();

            if ($existingWithKey) {
                // Key already exists, skip to avoid duplicate
                continue;
            }

            // Only update the first module with this name that doesn't have a key
            // This prevents duplicate key errors
            $moduleWithoutKey = \DB::table('modules')
                ->where('name', $name)
                ->whereNull('key')
                ->first();

            if ($moduleWithoutKey) {
                \DB::table('modules')
                    ->where('id', $moduleWithoutKey->id)
                    ->update(['key' => $key]);
            }
        }
    }
};
