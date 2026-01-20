<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Check if a "default" plan exists
        $defaultPlan = DB::table('plans')->where('key', 'default')->first();
        
        if ($defaultPlan) {
            // Check if "free" plan already exists
            $freePlan = DB::table('plans')->where('key', 'free')->first();
            
            if ($freePlan) {
                // If both exist, migrate subscriptions from default to free, then delete default
                DB::table('project_subscriptions')
                    ->where('plan_id', $defaultPlan->id)
                    ->update(['plan_id' => $freePlan->id]);
                
                // Delete the default plan
                DB::table('plans')->where('key', 'default')->delete();
            } else {
                // If only default exists, rename it to free
                DB::table('plans')
                    ->where('key', 'default')
                    ->update([
                        'key' => 'free',
                        'name' => 'Free',
                    ]);
            }
        }
        
        // Migrate features.modules to features.modules_allowed for all plans
        $plans = DB::table('plans')->get();
        
        foreach ($plans as $plan) {
            $features = json_decode($plan->features, true);
            
            if (isset($features['modules']) && !isset($features['modules_allowed'])) {
                $features['modules_allowed'] = $features['modules'];
                // Keep modules for backward compatibility
                
                DB::table('plans')
                    ->where('id', $plan->id)
                    ->update(['features' => json_encode($features)]);
            }
        }
    }

    public function down(): void
    {
        // Cannot safely reverse this migration
    }
};
