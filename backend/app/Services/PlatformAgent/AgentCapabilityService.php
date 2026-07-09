<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentConstants;
use App\Models\Agent;
use App\Models\Project;
use App\Services\EntitlementService;

/**
 * Resolves effective agent capabilities:
 *   workspace subscription ∩ module entitlements ∩ RBAC ∩ explicit agent permissions
 */
class AgentCapabilityService
{
    public function __construct(
        private EntitlementService $entitlementService
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function resolveGrantedCapabilities(Project $workspace, array $requestedPermissions): array
    {
        $entitlements = $this->entitlementService->getEntitlements($workspace);
        $modulesAllowed = $entitlements['modules_allowed'] ?? [];
        $granted = [];

        foreach (AgentConstants::PERMISSIONS as $permKey => $permMeta) {
            if (! in_array($permKey, $requestedPermissions, true)) {
                continue;
            }

            if (($permMeta['default'] ?? true) === false && ! in_array($permKey, $requestedPermissions, true)) {
                continue;
            }

            $module = $permMeta['module'] ?? null;
            if ($module && ! in_array($module, $modulesAllowed, true)) {
                continue;
            }

            foreach ($permMeta['capabilities'] ?? [] as $cap) {
                $granted[] = $cap;
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * @return array<int, string>
     */
    public function resolveEnabledModules(Project $workspace, array $grantedCapabilities): array
    {
        $modules = [];
        foreach ($grantedCapabilities as $cap) {
            $meta = AgentConstants::CAPABILITIES[$cap] ?? null;
            if ($meta && isset($meta['module'])) {
                $modules[] = $meta['module'];
            }
        }

        return array_values(array_unique($modules));
    }

    /**
     * @return array<string, array{status: string, reason?: string}>
     */
    public function buildCapabilityMatrix(Agent $agent, Project $workspace): array
    {
        $entitlements = $this->entitlementService->getEntitlements($workspace);
        $modulesAllowed = $entitlements['modules_allowed'] ?? [];
        $agentCaps = $agent->capabilities ?? [];
        $agentPerms = $agent->permissions ?? [];
        $matrix = [];

        foreach (AgentConstants::CAPABILITIES as $capKey => $capMeta) {
            $module = $capMeta['module'] ?? null;
            $subscriptionOk = $module === null || in_array($module, $modulesAllowed, true);
            $permissionOk = $this->capabilityAllowedByPermissions($capKey, $agentPerms);
            $enabled = in_array($capKey, $agentCaps, true);

            if (! $subscriptionOk) {
                $matrix[$capKey] = ['status' => 'disabled_subscription', 'reason' => "Module {$module} not in plan"];
            } elseif (! $permissionOk) {
                $matrix[$capKey] = ['status' => 'disabled_permission', 'reason' => 'Permission not granted'];
            } elseif (($capMeta['dangerous'] ?? false) && ! $enabled) {
                $matrix[$capKey] = ['status' => 'disabled_approval', 'reason' => 'Requires explicit approval'];
            } elseif ($enabled) {
                $matrix[$capKey] = ['status' => 'enabled'];
            } else {
                $matrix[$capKey] = ['status' => 'available'];
            }
        }

        return $matrix;
    }

    /**
     * @param array<int, string> $permissions
     */
    private function capabilityAllowedByPermissions(string $capability, array $permissions): bool
    {
        foreach (AgentConstants::PERMISSIONS as $permKey => $permMeta) {
            if (! in_array($permKey, $permissions, true)) {
                continue;
            }
            if (in_array($capability, $permMeta['capabilities'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
