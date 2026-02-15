<?php

namespace App\Http\Controllers;

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
     * Binaries must be placed in storage/app/agents/{platform} (e.g. build from agent/ and copy).
     */
    public function download(string $platform): StreamedResponse|Response
    {
        $platform = strtolower($platform);
        if (! in_array($platform, self::ALLOWED_PLATFORMS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unsupported platform. Use one of: ' . implode(', ', self::ALLOWED_PLATFORMS),
            ], 404);
        }

        $path = storage_path('app/agents/' . $platform);
        if (! File::isFile($path)) {
            Log::warning('Agent binary not found', ['platform' => $platform, 'path' => $path]);

            return response()->json([
                'success' => false,
                'message' => 'Agent binary not available for this platform. Build from source (agent/) and place at storage/app/agents/' . $platform,
            ], 404);
        }

        $filename = $platform === 'windows-amd64' || $platform === 'windows-arm64'
            ? 'portshield-agent.exe'
            : 'portshield-agent';

        return response()->streamDownload(function () use ($path) {
            $stream = fopen($path, 'rb');
            if ($stream) {
                while (! feof($stream)) {
                    echo fread($stream, 8192);
                    flush();
                }
                fclose($stream);
            }
        }, $filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }
}
