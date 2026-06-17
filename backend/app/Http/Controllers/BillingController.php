<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\BillingIntegration;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(
        private EntitlementService $entitlementService
    ) {}

    /**
     * GET /api/workspaces/{project}/billing/summary
     */
    public function summary(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $plan = $this->entitlementService->getEffectivePlan($project);
        $subscription = $project->subscription;

        $hostsCount = ObserveTargetHost::where('workspace_id', $project->id)->count();
        $agentsCount = Agent::where('workspace_id', $project->id)->count();

        $integrations = BillingIntegration::where('workspace_id', $project->id)->get();
        $connected = $integrations->first(fn ($i) => $i->status === 'connected');
        $configured = $integrations->first(fn ($i) => $i->status === 'configured');

        return response()->json([
            'success' => true,
            'data' => [
                'current_plan' => [
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'price_cents' => $plan->price_cents,
                    'interval' => $plan->interval,
                    'status' => $subscription?->status ?? 'active',
                ],
                'workspace_usage' => [
                    'monitored_hosts' => $hostsCount,
                    'agents' => $agentsCount,
                ],
                'billing_integration_status' => $connected ? 'connected' : ($configured ? 'configured' : 'not_connected'),
                'cost_data_sources' => $integrations->map(fn ($i) => [
                    'provider_type' => $i->provider_type,
                    'status' => $i->status,
                    'connected_at' => $i->connected_at?->toIso8601String(),
                ])->values(),
                'billing_contact' => $integrations->first()?->billing_contact,
                'invoices_available' => false,
            ],
        ]);
    }

    /**
     * GET /api/workspaces/{project}/billing/integrations
     */
    public function integrations(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $rows = BillingIntegration::where('workspace_id', $project->id)
            ->orderBy('provider_type')
            ->get()
            ->map(fn (BillingIntegration $i) => [
                'id' => $i->id,
                'provider_type' => $i->provider_type,
                'status' => $i->status,
                'config' => $this->redactConfig($i->config ?? []),
                'billing_contact' => $i->billing_contact,
                'connected_at' => $i->connected_at?->toIso8601String(),
                'updated_at' => $i->updated_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * POST /api/workspaces/{project}/billing/integrations
     */
    public function storeIntegration(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'provider_type' => ['required', 'string', Rule::in(BillingIntegration::PROVIDERS)],
            'status' => ['nullable', 'string', Rule::in(['not_connected', 'configured', 'connected'])],
            'config' => ['nullable', 'array'],
            'billing_contact' => ['nullable', 'string', 'max:255'],
        ]);

        $status = $validated['status'] ?? 'configured';
        if (in_array($validated['provider_type'], ['aws', 'azure', 'gcp', 'oracle_cloud'], true)) {
            $status = 'not_connected';
        }

        $integration = BillingIntegration::updateOrCreate(
            [
                'workspace_id' => $project->id,
                'provider_type' => $validated['provider_type'],
            ],
            [
                'status' => $status,
                'config' => $validated['config'] ?? [],
                'billing_contact' => $validated['billing_contact'] ?? null,
                'connected_at' => $status === 'connected' ? now() : null,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $integration->id,
                'provider_type' => $integration->provider_type,
                'status' => $integration->status,
                'config' => $this->redactConfig($integration->config ?? []),
                'billing_contact' => $integration->billing_contact,
                'connected_at' => $integration->connected_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function redactConfig(array $config): array
    {
        $redacted = $config;
        foreach (['api_key', 'secret', 'access_key', 'password'] as $key) {
            if (isset($redacted[$key]) && is_string($redacted[$key]) && $redacted[$key] !== '') {
                $redacted[$key] = '••••••••';
            }
        }

        return $redacted;
    }
}
