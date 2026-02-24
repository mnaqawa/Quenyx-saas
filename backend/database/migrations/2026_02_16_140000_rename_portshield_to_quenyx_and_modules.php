<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rename brand PortShield → Quenyx and module keys/names to Qyn*.
     * Run after ModuleSeeder has been updated to use new keys (re-seed will create new rows if missing).
     */
    public function up(): void
    {
        $map = [
            'shieldcore' => ['key' => 'qyncore', 'name' => 'QynCore'],
            'shieldobserve' => ['key' => 'qynsight', 'name' => 'QynSight'],
            'shieldinventory' => ['key' => 'qynasset', 'name' => 'QynAsset'],
            'shieldrespond' => ['key' => 'qynreact', 'name' => 'QynReact'],
            'shieldsecure' => ['key' => 'qynshield', 'name' => 'QynShield'],
            'shieldnotify' => ['key' => 'qynnotify', 'name' => 'QynNotify'],
            'shieldvoice' => ['key' => 'qynva', 'name' => 'QynVA'],
            'shieldknowledge' => ['key' => 'qynknow', 'name' => 'QynKnow'],
            'shieldautomate' => ['key' => 'qynrun', 'name' => 'QynRun'],
            'shieldbalance' => ['key' => 'qynbalance', 'name' => 'QynBalance'],
            'shielddesk' => ['key' => 'qynsupport', 'name' => 'QynSupport'],
            'shieldintegrations' => ['key' => 'qynintegrations', 'name' => 'QynIntegrations'],
        ];

        foreach ($map as $oldKey => $new) {
            DB::table('modules')->where('key', $oldKey)->update([
                'key' => $new['key'],
                'name' => $new['name'],
            ]);
        }

        // Update plan features: modules_allowed stored as JSON with old keys
        $plans = DB::table('plans')->get();
        foreach ($plans as $plan) {
            $features = json_decode($plan->features, true);
            if (! isset($features['modules_allowed']) || ! is_array($features['modules_allowed'])) {
                continue;
            }
            $allowed = $features['modules_allowed'];
            $updated = false;
            foreach ($map as $oldKey => $new) {
                $idx = array_search($oldKey, $allowed, true);
                if ($idx !== false) {
                    $allowed[$idx] = $new['key'];
                    $updated = true;
                }
            }
            if ($updated) {
                $features['modules_allowed'] = $allowed;
                DB::table('plans')->where('id', $plan->id)->update([
                    'features' => json_encode($features),
                ]);
            }
        }
    }

    public function down(): void
    {
        $map = [
            'qyncore' => ['key' => 'shieldcore', 'name' => 'ShieldCore'],
            'qynsight' => ['key' => 'shieldobserve', 'name' => 'ShieldObserve'],
            'qynasset' => ['key' => 'shieldinventory', 'name' => 'ShieldInventory'],
            'qynreact' => ['key' => 'shieldrespond', 'name' => 'ShieldRespond'],
            'qynshield' => ['key' => 'shieldsecure', 'name' => 'ShieldSecure'],
            'qynnotify' => ['key' => 'shieldnotify', 'name' => 'ShieldNotify'],
            'qynva' => ['key' => 'shieldvoice', 'name' => 'ShieldVoice'],
            'qynknow' => ['key' => 'shieldknowledge', 'name' => 'ShieldKnowledge'],
            'qynrun' => ['key' => 'shieldautomate', 'name' => 'ShieldAutomate'],
            'qynbalance' => ['key' => 'shieldbalance', 'name' => 'ShieldBalance'],
            'qynsupport' => ['key' => 'shielddesk', 'name' => 'ShieldDesk'],
            'qynintegrations' => ['key' => 'shieldintegrations', 'name' => 'ShieldIntegrations'],
        ];

        foreach ($map as $newKey => $old) {
            DB::table('modules')->where('key', $newKey)->update([
                'key' => $old['key'],
                'name' => $old['name'],
            ]);
        }

        $plans = DB::table('plans')->get();
        foreach ($plans as $plan) {
            $features = json_decode($plan->features, true);
            if (! isset($features['modules_allowed']) || ! is_array($features['modules_allowed'])) {
                continue;
            }
            $allowed = $features['modules_allowed'];
            $updated = false;
            foreach ($map as $newKey => $old) {
                $idx = array_search($newKey, $allowed, true);
                if ($idx !== false) {
                    $allowed[$idx] = $old['key'];
                    $updated = true;
                }
            }
            if ($updated) {
                $features['modules_allowed'] = $allowed;
                DB::table('plans')->where('id', $plan->id)->update([
                    'features' => json_encode($features),
                ]);
            }
        }
    }
};
