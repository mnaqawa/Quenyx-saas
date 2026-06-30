<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Platform\EventBus\PlatformEventBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 25 — Platform Event Bus introspection API. Read-only: the event vocabulary, registered
 * subscribers, and recent in-process events. Privileged (`administerAi`).
 */
class EventBusController extends QynVaBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly PlatformEventBus $bus,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function describe(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAdmin($project);

        return $this->ok([
            'bus' => $this->bus->describe(),
            'recent' => $this->bus->recent((int) $request->query('limit', '25')),
        ]);
    }
}
