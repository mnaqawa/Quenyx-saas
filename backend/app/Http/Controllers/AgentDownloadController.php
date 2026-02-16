<?php

namespace App\Http\Controllers;

use App\Services\AgentBuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentDownloadController extends Controller
{
    private const ALLOWED_PLATFORMS = [
        'linux-amd64',
        'linux-arm64',
        'windows-amd64',
        'windows-arm64',
        'darwin-amd64',
        'darwin-arm64',
    ];

    /**
     * Serve the agent binary for the given platform.
     * GET /api/agents/download/{platform}
     *
     * If the binary is missing and build_on_demand is enabled, builds it from agent/ (requires Go).
     */
    public function download(string $platform, AgentBuildService $buildService): StreamedResponse|Response|JsonResponse
    {
        $platform = strtolower($platform);
        if (! in_array($platform, self::ALLOWED_PLATFORMS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unsupported platform. Use one of: ' . implode(', ', self::ALLOWED_PLATFORMS),
            ], 404)->header('Content-Type', 'application/json');
        }

        $path = storage_path('app/agents/' . $platform);
        if (! File::isFile($path)) {
            if (config('agent.build_on_demand', true)) {
                $built = $buildService->build($platform);
                if ($built !== null) {
                    $path = $built;
                }
            }
            if (! File::isFile($path)) {
                $reason = $buildService->getLastError();
                $message = $reason ?: 'Agent binary not available for this platform. Build from source (agent/) and place at storage/app/agents/' . $platform;
                Log::warning('Agent binary not found', ['platform' => $platform, 'path' => $path, 'reason' => $reason]);

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 404)->header('Content-Type', 'application/json');
            }
        }

        $filename = $platform === 'windows-amd64' || $platform === 'windows-arm64'
            ? 'portshield-agent.exe'
            : 'portshield-agent';

        try {
            return response()->streamDownload(function () use ($path) {
                $stream = fopen($path, 'rb');
                if (! $stream) {
                    throw new \RuntimeException('Could not open file');
                }
                try {
                    while (! feof($stream)) {
                        echo fread($stream, 8192);
                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();
                    }
                } finally {
                    fclose($stream);
                }
            }, $filename, [
                'Content-Type' => 'application/octet-stream',
            ]);
        } catch (\Throwable $e) {
            Log::error('Agent download failed', ['platform' => $platform, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Download failed. The agent binary may not be available for this platform.',
            ], 500)->header('Content-Type', 'application/json');
        }
    }
}
