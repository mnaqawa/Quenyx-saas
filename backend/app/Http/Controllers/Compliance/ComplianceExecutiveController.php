<?php

namespace App\Http\Controllers\Compliance;

use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Compliance\ComplianceCorpusAccessAuditLogger;
use App\Services\Compliance\Executive\ComplianceExecutiveDashboardService;
use App\Services\Compliance\Executive\ComplianceExecutiveTimelineService;
use App\Services\Compliance\Executive\ComplianceExplainabilityService;
use App\Services\Compliance\Executive\ComplianceHealthScorecardService;
use App\Services\Compliance\Executive\CompliancePlatformMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace-scoped Executive Demonstration Platform API (QCIF Sprint 18).
 *
 * Read-only, UUID-only, deterministic endpoints that EXPOSE the intelligence already built by the
 * QCIF engines (corpus, gap, recommendation, reasoning, retrieval). It creates NO new intelligence
 * and NO data — every value is a live, real result. Access: sanctum + project membership +
 * QynShield entitlement + audit + rate limit.
 */
class ComplianceExecutiveController extends Controller
{
    public function __construct(
        private readonly ComplianceExecutiveDashboardService $dashboard,
        private readonly ComplianceHealthScorecardService $scorecard,
        private readonly ComplianceExecutiveTimelineService $timeline,
        private readonly ComplianceExplainabilityService $explainability,
        private readonly CompliancePlatformMetricsService $platform,
        private readonly ComplianceCorpusAccessAuditLogger $auditLogger,
    ) {}

    public function dashboard(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        [$framework, $release] = $this->scope($request);
        $this->audit($request, $project, 'executive.dashboard', $framework, $release);

        return $this->guarded(fn () => $this->dashboard->dashboard($framework, $release, $project->id));
    }

    public function scorecard(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        [$framework, $release] = $this->scope($request);
        $this->audit($request, $project, 'executive.scorecard', $framework, $release);

        return $this->guarded(fn () => $this->scorecard->scorecard($framework, $release, $project->id));
    }

    public function timeline(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        [$framework, $release] = $this->scope($request);
        $validated = $request->validate(['limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200']]);
        $this->audit($request, $project, 'executive.timeline', $framework, $release);

        return $this->guarded(fn () => $this->timeline->timeline($framework, $release, $project->id, isset($validated['limit']) ? (int) $validated['limit'] : null));
    }

    public function explainability(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        [$framework, $release] = $this->scope($request);

        $validated = $request->validate([
            'subject' => ['sometimes', 'nullable', 'string', 'in:requirement,recommendation'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        $subject = $validated['subject'] ?? 'requirement';
        $code = (string) $validated['code'];
        $this->audit($request, $project, 'executive.explainability', $framework, $release);

        return $this->guarded(fn () => $subject === 'recommendation'
            ? $this->explainability->explainRecommendation($framework, $release, $project->id, $code)
            : $this->explainability->explainRequirement($framework, $release, $project->id, $code));
    }

    public function platform(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $this->audit($request, $project, 'executive.platform', null, null);

        return response()->json(['success' => true, 'data' => $this->platform->metrics()]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  callable(): array<string, mixed>  $builder
     */
    private function guarded(callable $builder): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $builder()]);
        } catch (ComplianceCorpusNotFoundException $e) {
            return response()->json(['success' => false, 'error_code' => 'not_found', 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function scope(Request $request): array
    {
        $validated = $request->validate([
            'framework' => ['sometimes', 'nullable', 'string', 'max:64'],
            'release' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        return [$this->str($validated['framework'] ?? null), $this->str($validated['release'] ?? null)];
    }

    private function audit(Request $request, Project $project, string $endpoint, ?string $framework, ?string $release): void
    {
        $user = $request->user();
        if ($user !== null) {
            $this->auditLogger->logRetrieval($user, $project, $endpoint, $endpoint, $framework, $release);
        }
    }

    private function str(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
