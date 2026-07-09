<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentConstants;
use App\Models\Project;
use App\Support\AgentGateway;

/**
 * Enterprise installer catalog metadata (silent install flags included).
 */
class InstallerCatalogService
{
    /**
     * @return array<string, mixed>
     */
    public function catalog(Project $project, ?string $enrollmentToken = null): array
    {
        $gatewayUrl = AgentGateway::url();
        $workspaceId = $project->id;
        $baseConfig = [
            'gateway_url' => $gatewayUrl,
            'workspace_id' => $workspaceId,
            'workspace_uuid' => $project->uuid ?? null,
            'enrollment_token' => $enrollmentToken,
            'agent_version' => AgentConstants::AGENT_VERSION,
            'policy_version' => config('agent.policy.version', '1.0.0'),
        ];

        return [
            'config' => $baseConfig,
            'installers' => [
                'linux' => [
                    ['format' => 'rpm', 'arch' => 'x86_64', 'silent' => 'rpm -i quenyx-agent.rpm --quiet'],
                    ['format' => 'deb', 'arch' => 'amd64', 'silent' => 'dpkg -i quenyx-agent.deb'],
                    ['format' => 'tar', 'arch' => 'all', 'silent' => 'tar -xzf quenyx-agent.tar.gz && ./install.sh --silent'],
                ],
                'windows' => [
                    ['format' => 'msi', 'arch' => 'x86_64', 'silent' => 'msiexec /i QuenyxAgent.msi /qn GATEWAY_URL='.$gatewayUrl.' WORKSPACE_ID='.$workspaceId],
                    ['format' => 'exe', 'arch' => 'x86_64', 'silent' => 'QuenyxAgentSetup.exe /S /GATEWAY='.$gatewayUrl.' /WORKSPACE='.$workspaceId],
                ],
                'macos' => [
                    ['format' => 'pkg', 'arch' => 'universal', 'silent' => 'installer -pkg QuenyxAgent.pkg -target /'],
                ],
                'container' => [
                    ['format' => 'docker', 'image' => 'quenyx/platform-agent:'.AgentConstants::AGENT_VERSION, 'run' => $this->dockerRun($baseConfig)],
                    ['format' => 'kubernetes', 'manifest' => 'quenyx-agent-daemonset.yaml'],
                    ['format' => 'helm', 'chart' => 'quenyx/platform-agent', 'values' => $baseConfig],
                ],
            ],
            'enroll_command' => $enrollmentToken
                ? sprintf('./quenyx-agent enroll --url="%s" --workspace=%d --token="%s"', $gatewayUrl, $workspaceId, $enrollmentToken)
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function dockerRun(array $config): string
    {
        return sprintf(
            'docker run -d --name quenyx-agent -e QAG_URL=%s -e WORKSPACE_ID=%d quenyx/platform-agent:%s',
            $config['gateway_url'],
            $config['workspace_id'],
            $config['agent_version']
        );
    }
}
