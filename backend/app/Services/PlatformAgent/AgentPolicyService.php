<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentConstants;
use App\Constants\AgentPolicyStatus;
use App\Models\Agent;

/**
 * Derives policy sync status from heartbeat version fields.
 */
class AgentPolicyService
{
    public function currentPolicyVersion(): string
    {
        return (string) config('agent.policy.version', '1.0.0');
    }

    public function currentPlatformVersion(): string
    {
        return (string) config('agent.policy.platform_version', '1.0.0');
    }

    /**
     * @param  array<string, string>  $pluginVersions
     */
    public function evaluate(Agent $agent, ?string $agentVersion, ?string $policyVersion, ?string $platformVersion, array $pluginVersions = []): string
    {
        $agentVersion = $this->normalizeVersion(
            $agentVersion ?? $agent->agent_version ?? AgentConstants::AGENT_VERSION
        );
        $policyVersion = $policyVersion ?? $agent->policy_version;
        $platformVersion = $platformVersion ?? $agent->platform_version;

        $minSupported = $this->normalizeVersion(
            (string) config('agent.policy.min_supported_agent_version', '1.0.0')
        );
        $supported = config('agent.policy.supported_agent_versions', ['1.0.0', '1.0.1']);
        if (! is_array($supported)) {
            $supported = ['1.0.0', '1.0.1'];
        }
        $supportedNormalized = array_map(fn ($v) => $this->normalizeVersion((string) $v), $supported);

        // Unsupported only when below the minimum supported floor (not a brittle exact allowlist).
        // Exact allowlist remains advisory — 1.0.1 must not fail when list mistakenly skipped a patch.
        if ($agentVersion === '' || version_compare($agentVersion, $minSupported, '<')) {
            return AgentPolicyStatus::UNSUPPORTED_VERSION;
        }

        // Optional hard deny: if allowlist is non-empty and version is neither listed nor a known patch
        // of a listed line above min — already covered by min check. Keep allowlist for docs only.

        $latestAgent = $this->normalizeVersion(
            (string) config('agent.policy.latest_agent_version', AgentConstants::AGENT_VERSION)
        );
        if ($latestAgent !== '' && version_compare($agentVersion, $latestAgent, '<')) {
            return AgentPolicyStatus::UPGRADE_AVAILABLE;
        }

        if ($policyVersion === null || $policyVersion === '') {
            return AgentPolicyStatus::POLICY_SYNC_REQUIRED;
        }

        if ($policyVersion !== $this->currentPolicyVersion()) {
            return AgentPolicyStatus::POLICY_OUTDATED;
        }

        if ($platformVersion !== null && $platformVersion !== '' && $platformVersion !== $this->currentPlatformVersion()) {
            return AgentPolicyStatus::POLICY_OUTDATED;
        }

        return AgentPolicyStatus::UP_TO_DATE;
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if ($version === '') {
            return '';
        }
        if (str_starts_with(strtolower($version), 'v')) {
            $version = substr($version, 1);
        }

        // Keep only core semver pieces for comparison (1.0.1-dev → 1.0.1).
        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d+\.\d+)/', $version, $m)) {
            return $m[1].'.0';
        }

        return $version;
    }

    public function capabilityHash(array $capabilities): string
    {
        sort($capabilities);

        return hash('sha256', json_encode($capabilities, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function policyPayload(Agent $agent): array
    {
        return [
            'policy_version' => $this->currentPolicyVersion(),
            'platform_version' => $this->currentPlatformVersion(),
            'latest_agent_version' => config('agent.policy.latest_agent_version', AgentConstants::AGENT_VERSION),
            'min_supported_agent_version' => config('agent.policy.min_supported_agent_version', '1.0.0'),
            'supported_agent_versions' => config('agent.policy.supported_agent_versions', ['1.0.0', '1.0.1']),
            'policy_status' => $agent->policy_status ?? AgentPolicyStatus::UP_TO_DATE,
            'capability_hash' => $agent->capability_hash,
        ];
    }
}
