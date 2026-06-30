<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Platform\Health\PlatformHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 25 — Platform Health API (the platform monitoring itself). Privileged: requires `administerAi`
 * since it exposes registry/provider/queue internals.
 */
class PlatformHealthController extends QynVaBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly PlatformHealthService $health,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAdmin($project);

        return $this->ok($this->health->snapshot($project));
    }
}
