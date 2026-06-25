<?php

namespace App\Http\Controllers\QuenyxAI;

use App\Http\Controllers\Controller;
use App\Services\QuenyxAI\QuenyxAiPlatform;
use Illuminate\Http\JsonResponse;

/**
 * Quenyx AI Platform API (QCIF Sprint 19).
 *
 * Exposes the dynamically-generated platform capability catalog: which modules, skills, providers,
 * reasoning rules, retrieval modes, and RAG configuration the shared AI platform offers. Read-only;
 * platform-level (not tenant data); requires authentication.
 */
class QuenyxAiPlatformController extends Controller
{
    public function __construct(private readonly QuenyxAiPlatform $platform) {}

    public function capabilities(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->platform->capabilities(),
        ]);
    }
}
