<?php

namespace App\Services\QuenyxAI;

/**
 * Backend-authoritative catalog of Quenyx vOPS HUB modules (QCIF Sprint 19 — Additional Architecture
 * Note).
 *
 * This makes the Quenyx AI Platform MODULE-AWARE of the entire HUB, completely independent of the
 * frontend sidebar visibility flags (e.g. HIDE_NON_QYNSIGHT_MODULES). A module hidden from the UI is
 * still known to the platform here. For each module it reports the AI readiness:
 *
 *   - production : an adapter is registered with the platform (e.g. QynShield).
 *   - reserved   : an adapter contract exists but is not implemented yet (e.g. QynSight).
 *   - planned    : on the roadmap; no adapter contract yet.
 *
 * This service reads only config (config/quenyx_ai.php) + the live adapter registrations — no DB, no
 * AI, no frontend coupling.
 */
class QuenyxModuleCatalog
{
    /**
     * The raw, UI-independent module universe from config.
     *
     * @return list<array{key: string, name: string, ai_candidate: bool}>
     */
    public function all(): array
    {
        $modules = [];
        foreach ((array) config('quenyx_ai.modules', []) as $module) {
            if (! is_array($module) || ! isset($module['key'])) {
                continue;
            }
            $modules[] = [
                'key' => (string) $module['key'],
                'name' => (string) ($module['name'] ?? $module['key']),
                'ai_candidate' => (bool) ($module['ai_candidate'] ?? false),
            ];
        }

        return $modules;
    }

    /**
     * Describe every module with its live AI readiness against the platform.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(QuenyxAiPlatform $platform): array
    {
        $reserved = (array) config('quenyx_ai.reserved_adapters', []);

        return array_map(function (array $module) use ($platform, $reserved): array {
            $key = $module['key'];
            $registered = $platform->hasAdapter($key);
            $hasReservedContract = isset($reserved[$key]) && interface_exists((string) $reserved[$key]);

            $status = $registered ? 'production' : ($hasReservedContract ? 'reserved' : 'planned');

            return [
                'key' => $key,
                'name' => $module['name'],
                'ai_status' => $status,
                'adapter_registered' => $registered,
                'first_class_ai_candidate' => $module['ai_candidate'],
                'reserved_contract' => $hasReservedContract ? (string) $reserved[$key] : null,
            ];
        }, $this->all());
    }
}
