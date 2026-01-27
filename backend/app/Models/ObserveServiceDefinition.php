<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Canonical service definition for ShieldObserve.
 * Engine-agnostic; used for UI-driven service creation and to fix argument ordering (e.g. check_ping).
 *
 * args_schema contract (MUST be an ordered list; Nagios commands are positional):
 * - MUST be a JSON array, not an object.
 * - Each element MUST include: position (int), key (string), default (mixed), required (bool).
 * - Argument order is defined by position; UI and generators must not define order themselves.
 *
 * Capability flags (canonical set; drive UI + validation, not decoration):
 * - supports_thresholds  Warn/Critical
 * - supports_ports      TCP/UDP port
 * - supports_urls       URL/path
 * - supports_auth       Credentials
 * - supports_payload    Body/POST data
 * - supports_intervals  Check interval
 * - supports_retries    Retry policy
 */
class ObserveServiceDefinition extends Model
{
    protected $table = 'observe_service_definitions';

    protected $fillable = [
        'engine',
        'service_key',
        'display_name',
        'check_command',
        'args_schema',
        'capability_flags',
        'status',
    ];

    protected $casts = [
        'args_schema' => 'array',
        'capability_flags' => 'array',
    ];

    /**
     * Scope: active definitions only.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: for a given engine.
     */
    public function scopeForEngine($query, string $engine)
    {
        return $query->where('engine', $engine);
    }

    /**
     * Whether this definition has a given capability flag.
     */
    public function hasCapability(string $flag): bool
    {
        $flags = $this->capability_flags ?? [];
        return is_array($flags) && in_array($flag, $flags, true);
    }

    /**
     * Args as ordered list (position ascending). Ensures no engine syntax leaks; order comes from args_schema only.
     */
    public function getOrderedArgsSchema(): array
    {
        $schema = $this->args_schema ?? [];
        if (!is_array($schema)) {
            return [];
        }
        usort($schema, fn($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));
        return $schema;
    }
}
