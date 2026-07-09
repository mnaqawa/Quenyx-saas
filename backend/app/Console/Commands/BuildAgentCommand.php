<?php

namespace App\Console\Commands;

use App\Services\AgentBuildService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BuildAgentCommand extends Command
{
    protected $signature = 'agent:build
                            {platform? : Target platform (linux-amd64, linux-arm64, windows-amd64, windows-arm64, darwin-amd64, darwin-arm64)}
                            {--all : Build all supported platforms}';

    protected $description = 'Build Quenyx Platform Agent binaries into storage/app/agents/';

    public function handle(AgentBuildService $buildService): int
    {
        $platforms = $this->option('all')
            ? ['linux-amd64', 'linux-arm64', 'windows-amd64', 'windows-arm64', 'darwin-amd64', 'darwin-arm64']
            : [strtolower((string) ($this->argument('platform') ?: 'linux-amd64'))];

        $failed = 0;
        foreach ($platforms as $platform) {
            if (! AgentBuildService::isPlatformSupported($platform)) {
                $this->error("Unsupported platform: {$platform}");
                $failed++;

                continue;
            }

            $this->line("Building {$platform}…");
            $path = $buildService->build($platform);
            if ($path === null || ! File::isFile($path)) {
                $this->error($buildService->getLastError() ?? "Build failed for {$platform}");
                $failed++;

                continue;
            }

            $size = File::size($path);
            $this->info("✓ {$platform} → {$path} (".number_format($size).' bytes)');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
