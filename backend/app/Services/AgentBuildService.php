<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class AgentBuildService
{
    /** Last failure reason, for API to return to the client. */
    private ?string $lastError = null;

    private const PLATFORM_MAP = [
        'linux-amd64' => ['GOOS' => 'linux', 'GOARCH' => 'amd64', 'ext' => ''],
        'linux-arm64' => ['GOOS' => 'linux', 'GOARCH' => 'arm64', 'ext' => ''],
        'windows-amd64' => ['GOOS' => 'windows', 'GOARCH' => 'amd64', 'ext' => '.exe'],
        'windows-arm64' => ['GOOS' => 'windows', 'GOARCH' => 'arm64', 'ext' => '.exe'],
        'darwin-amd64' => ['GOOS' => 'darwin', 'GOARCH' => 'amd64', 'ext' => ''],
        'darwin-arm64' => ['GOOS' => 'darwin', 'GOARCH' => 'arm64', 'ext' => ''],
    ];

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Build the agent for the given platform and save to storage. Returns path to the built file or null on failure.
     */
    public function build(string $platform): ?string
    {
        $this->lastError = null;
        $platform = strtolower($platform);
        $map = self::PLATFORM_MAP[$platform] ?? null;
        if (! $map) {
            $this->lastError = 'Unsupported platform.';
            return null;
        }

        $sourcePath = rtrim(config('agent.source_path', base_path('../agent')), '/');
        if (! is_dir($sourcePath) || ! File::isFile($sourcePath . '/go.mod')) {
            $this->lastError = 'Agent source not found at ' . $sourcePath . '. Set AGENT_SOURCE_PATH in .env to the directory containing go.mod.';
            Log::warning('Agent source not found', ['path' => $sourcePath]);
            return null;
        }

        $goBinary = config('agent.go_binary', 'go');

        $outDir = storage_path('app/agents');
        $outPath = $outDir . '/' . $platform;

        try {
            if (! File::isDirectory($outDir)) {
                File::makeDirectory($outDir, 0755, true);
            }
            $goCacheDir = $outDir . '/.gocache';
            $goModCacheDir = $outDir . '/.gomodcache';
            if (! File::isDirectory($goCacheDir)) {
                File::makeDirectory($goCacheDir, 0755, true);
            }
            if (! File::isDirectory($goModCacheDir)) {
                File::makeDirectory($goModCacheDir, 0755, true);
            }
        } catch (\Throwable $e) {
            $this->lastError = 'Cannot create storage/app/agents: ' . $e->getMessage() . '. Ensure the directory exists and is writable by the web server user.';
            Log::warning('Agent build: storage directory not writable', ['path' => $outDir, 'error' => $e->getMessage()]);
            return null;
        }

        $lockKey = 'agent-build-' . $platform;
        $lock = Cache::lock($lockKey, 120);
        if (! $lock->get()) {
            for ($i = 0; $i < 60; $i++) {
                usleep(500000);
                if (File::isFile($outPath)) {
                    return $outPath;
                }
            }
            $this->lastError = 'Build in progress or timed out. Try again in a minute.';
            return null;
        }

        try {
            $goCacheDir = $outDir . '/.gocache';
            $goModCacheDir = $outDir . '/.gomodcache';
            $env = array_merge(
                array_filter(getenv()),
                [
                    'GOOS' => $map['GOOS'],
                    'GOARCH' => $map['GOARCH'],
                    'GOCACHE' => $goCacheDir,
                    'GOMODCACHE' => $goModCacheDir,
                ]
            );

            $process = new Process(
                [$goBinary, 'build', '-o', $outPath, '.'],
                $sourcePath,
                $env,
                null,
                120
            );

            $process->run();

            if (! $process->isSuccessful()) {
                $out = $process->getErrorOutput() ?: $process->getOutput();
                $this->lastError = 'Build failed: ' . trim($out ?: $process->getErrorOutput() . $process->getOutput());
                if (str_contains($this->lastError, 'command not found') || str_contains($this->lastError, 'executable file not found')) {
                    $this->lastError = 'Go binary not found. Set AGENT_GO_BINARY in .env to the full path (e.g. /usr/bin/go).';
                } elseif (str_contains($this->lastError, 'build cache') && (str_contains($this->lastError, 'permission denied') || str_contains($this->lastError, 'Permission denied'))) {
                    $this->lastError = 'Go build cache permission denied. The app now uses storage/app/agents/.gocache; ensure storage/app/agents is writable by the web server user.';
                } elseif (str_contains($this->lastError, 'Permission denied') || str_contains($this->lastError, 'permission denied')) {
                    $this->lastError = 'Permission denied writing to storage/app/agents. Ensure backend/storage/app/agents exists and is writable by the web server user (e.g. chown www-data:www-data and chmod 775).';
                }
                Log::error('Agent build failed', ['platform' => $platform, 'output' => $out]);
                return null;
            }

            if (! File::isFile($outPath)) {
                $this->lastError = 'Build produced no binary. Check server logs.';
                Log::error('Agent build produced no file', ['platform' => $platform]);
                return null;
            }

            Log::info('Agent built successfully', ['platform' => $platform, 'path' => $outPath]);
            return $outPath;
        } finally {
            $lock->release();
        }
    }

    public static function isPlatformSupported(string $platform): bool
    {
        return isset(self::PLATFORM_MAP[strtolower($platform)]);
    }
}
