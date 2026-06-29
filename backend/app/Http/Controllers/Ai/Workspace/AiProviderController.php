<?php

namespace App\Http\Controllers\Ai\Workspace;

use App\Services\Ai\Workspace\AiProviderSettingsService;
use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 20 — workspace AI provider preferences. Listing needs access; updating needs the
 * can_manage_providers capability AND owner/admin (administerAi). Secrets are write-only: they are
 * stored encrypted and never returned (only a secret_configured indicator is exposed).
 */
class AiProviderController extends AiWorkspaceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        private readonly AiProviderSettingsService $service,
    ) {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok(['providers' => $this->service->list($project)]);
    }

    public function updateSettings(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request, 'administerAi');
        $this->requireCapability($project, $request, 'can_manage_providers');

        $providerKey = $this->service->resolveProviderKey($project, $uuid);
        abort_if($providerKey === null, 404, 'Provider not found.');

        $validated = $request->validate([
            'workspace' => ['required', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:512'],
            'organization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'clear_secrets' => ['sometimes', 'boolean'],
        ]);

        $result = $this->service->update($project, $request->user(), $providerKey, $validated);

        return $this->ok($result);
    }

    /**
     * Run a real readiness probe for a provider. Executable providers return their adapter's genuine
     * health(); non-executable catalog providers honestly report "not_executable". Audited.
     */
    public function test(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request, 'administerAi');
        $this->requireCapability($project, $request, 'can_manage_providers');

        $providerKey = $this->service->resolveProviderKey($project, $uuid);
        abort_if($providerKey === null, 404, 'Provider not found.');

        $request->validate(['workspace' => ['required', 'string']]);

        return $this->ok($this->service->test($project, $request->user(), $providerKey));
    }
}
