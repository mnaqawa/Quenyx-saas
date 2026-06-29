<?php

namespace App\Http\Controllers\Ai\Workspace;

use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\QuenyxAI\QuenyxAiPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 20 — exposes the existing, dynamically-generated Quenyx AI Platform catalog (Sprint 19)
 * to the workspace UI: the full capability explorer and the skills browser. No catalog logic is
 * duplicated — this is a workspace-access-gated read of QuenyxAiPlatform.
 */
class AiCapabilityController extends AiWorkspaceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        private readonly QuenyxAiPlatform $platform,
    ) {
        parent::__construct($context);
    }

    public function capabilities(Request $request): JsonResponse
    {
        $this->workspace($request);

        return $this->ok($this->platform->capabilities());
    }

    public function skills(Request $request): JsonResponse
    {
        $this->workspace($request);

        return $this->ok(['skills' => $this->platform->resolveSkills()->describe()]);
    }
}
