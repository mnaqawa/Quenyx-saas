<?php

namespace App\Helpers;

/**
 * Maps module names to their keys for plan entitlements
 * 
 * Module names in database: "ShieldCore", "ShieldObserve", etc.
 * Module keys for plans: "shieldcore", "shieldobserve", etc. (lowercase, no spaces)
 */
class ModuleKeyMapper
{
    /**
     * Convert module name to key
     * 
     * @param string $name
     * @return string
     */
    public static function nameToKey(string $name): string
    {
        return strtolower($name);
    }

    /**
     * Convert module key to name
     * 
     * @param string $key
     * @return string
     */
    public static function keyToName(string $key): string
    {
        // Convert "shieldcore" to "ShieldCore"
        return ucfirst($key);
    }

    /**
     * Get all module key mappings
     * 
     * @return array<string, string> [key => name]
     */
    public static function getMappings(): array
    {
        return [
            'shieldcore' => 'ShieldCore',
            'shieldobserve' => 'ShieldObserve',
            'shieldinventory' => 'ShieldInventory',
            'shieldrespond' => 'ShieldRespond',
            'shieldsecure' => 'ShieldSecure',
            'shieldnotify' => 'ShieldNotify',
            'shieldvoice' => 'ShieldVoice',
            'shieldknowledge' => 'ShieldKnowledge',
            'shieldautomate' => 'ShieldAutomate',
            'shieldbalance' => 'ShieldBalance',
            'shielddesk' => 'ShieldDesk',
        ];
    }
}
