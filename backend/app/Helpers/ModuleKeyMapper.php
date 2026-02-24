<?php

namespace App\Helpers;

/**
 * Maps module names to their keys for plan entitlements.
 *
 * Module names in database: "QynCore", "QynSight", etc.
 * Module keys for plans: "qyncore", "qynsight", etc. (lowercase)
 */
class ModuleKeyMapper
{
    public static function nameToKey(string $name): string
    {
        return strtolower($name);
    }

    public static function keyToName(string $key): string
    {
        $names = [
            'qynva' => 'QynVA',
            'qyncore' => 'QynCore',
            'qynsight' => 'QynSight',
            'qynasset' => 'QynAsset',
            'qynreact' => 'QynReact',
            'qynshield' => 'QynShield',
            'qynnotify' => 'QynNotify',
            'qynknow' => 'QynKnow',
            'qynrun' => 'QynRun',
            'qynbalance' => 'QynBalance',
            'qynsupport' => 'QynSupport',
            'qynintegrations' => 'QynIntegrations',
        ];

        return $names[$key] ?? ucfirst($key);
    }

    /**
     * @return array<string, string> [key => name]
     */
    public static function getMappings(): array
    {
        return [
            'qyncore' => 'QynCore',
            'qynsight' => 'QynSight',
            'qynasset' => 'QynAsset',
            'qynreact' => 'QynReact',
            'qynshield' => 'QynShield',
            'qynnotify' => 'QynNotify',
            'qynva' => 'QynVA',
            'qynknow' => 'QynKnow',
            'qynrun' => 'QynRun',
            'qynbalance' => 'QynBalance',
            'qynsupport' => 'QynSupport',
            'qynintegrations' => 'QynIntegrations',
        ];
    }
}
