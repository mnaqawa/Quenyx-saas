<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class AgentBuildService
{
    private const PLATFORM_MAP = [
        'linux-amd64' => ['GOOS' => 'linux', 'GOARCH' => 'amd64', 'ext' => ''],
        'linux-arm64' => ['GOOS' => 'linux', 'GOARCH' => 'arm64', 'ext' => ''],
        'windows-amd64' => ['GOOS' => 'windows', 'GOARCH' => 'amd64', 'ext' => '.exe'],
        'windows-arm64' => ['GOOS' => 'windows', 'GOARCH' => 'arm64', 'ext' => '.exe'],
        'darwin-amd64' => ['GOOS' => 'darwin', 'GOARCH' => 'amd64', 'ext' => ''],
        'darwin-arm64' => ['GOOS' => 'darwin', 'GOARCH' => 'arm64', 'ext' => ''],
    ];

    /**
     * Build the agent for the given platform and save to storage. Returns path to the built file or null on failure.
     */
    public function build(string $platform): ?string
    {
        $platform = strtolower($platform);
        $map = self::PLATFORM_MAP[$platform] ?? null;
        if (! $map) {
            return null;
        }

        $sourcePath = rtrim(config('agent.source_path', base_path('../agent')), '/');
        if (! is_dir($sourcePath) || ! File::isFile($sourcePath . '/go.mod')) {
            Log::warning('Agent source not found', ['path' => $sourcePath]);

            return null;
        }

        $outDir = storage_path('app/agents');
        if (! File::isDirectory($outDir)) {
            File::makeDirectory($outDir, 0755, true);
        }
        $outPath = $outDir . '/' . $platform;

        $lockPath = $outDir . '/.build-' . $platform . '.lock';
        $lock = fopen($lockPath, 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            for ($i = 0; $i < 60; $i++) {
                usleep(500000);
                if (File::isFile($outPath)) {
                    return $outPath;
                }
            }
            return null;
        }

        try {
            $env = array_merge(
                array_filter(getenv()),
                [
                    'GOOS' => $map['GOOS'],
                    'GOARCH' => $map['GOARCH'],
                ]
            );

            $process = new Process(
                ['go', 'build', '-o', $outPath, '.'],
                $sourcePath,
                $env,
                null,
                120
            );

            $process->run();

            if (! $process->isSuccessful()) {
                Log::error('Agent build failed', [
                    'platform' => $platform,
                    'output' => $process->getErrorOutput() ?: $process->getOutput(),
                ]);
                return null;
            }

            if (! File::isFile($outPath)) {
                Log::error('Agent build produced no file', ['platform' => $platform]);
                return null;
            }

            Log::info('Agent built successfully', ['platform' => $platform, 'path' => $outPath]);
            return $outPath;
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
            @unlink($lockPath);
        }
    }

    public static function isPlatformSupported(string $platform): bool
    {
        return isset(self::PLATFORM_MAP[strtolower($platform)]);
    }
}
