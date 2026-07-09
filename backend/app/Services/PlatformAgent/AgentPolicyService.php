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
     * @param array<string, string> $pluginVersions
     */
    public function evaluate(Agent $agent, ?string $agentVersion, ?string $policyVersion, ?string $platformVersion, array $pluginVersions = []): string
    {
        $agentVersion = $agentVersion ?? $agent->agent_version ?? AgentConstants::AGENT_VERSION;
        $policyVersion = $policyVersion ?? $agent->policy_version;
        $platformVersion = $platformVersion ?? $agent->platform_version;

        $supported = config('agent.policy.supported_agent_versions', ['1.0.0']);
        if (! in_array($agentVersion, $supported, true)) {
            return AgentPolicyStatus::UNSUPPORTED_VERSION;
        }

        $latestAgent = (string) config('agent.policy.latest_agent_version', AgentConstants::AGENT_VERSION);
        if (version_compare($agentVersion, $latestAgent, '<')) {
            return AgentPolicyStatus::UPGRADE_AVAILABLE;
        }

        if ($policyVersion === null || $policyVersion === '') {
            return AgentPolicyStatus::POLICY_SYNC_REQUIRED;
        }

        if ($policyVersion !== $this->currentPolicyVersion()) {
            return AgentPolicyStatus::POLICY_OUTDATED;
        }

        if ($platformVersion !== null && $platformVersion !== $this->currentPlatformVersion()) {
            return AgentPolicyStatus::POLICY_OUTDATED;
        }

        return AgentPolicyStatus::UP_TO_DATE;
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
            'supported_agent_versions' => config('agent.policy.supported_agent_versions', ['1.0.0']),
            'policy_status' => $agent->policy_status ?? AgentPolicyStatus::UP_TO_DATE,
            'capability_hash' => $agent->capability_hash,
        ];
    }
}
