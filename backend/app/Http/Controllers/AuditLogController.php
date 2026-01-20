<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Services\ProjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogController extends Controller
{
    public function __construct(
        private ProjectAccessService $accessService
    ) {
    }

    /**
     * Get audit logs for a project
     * Only project owners and admins can view audit logs
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            // Authorize: user must be owner or admin
            if (!$this->accessService->canManageProject($user, $project)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owners and admins can view audit logs',
                ], 403);
            }

            $logs = AuditLog::query()
                ->where('project_id', $project->id)
                ->with('user:id,name,email')
                ->orderBy('timestamp', 'desc')
                ->limit(50)
                ->get()
                ->map(function (AuditLog $log) {
                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'metadata' => $log->metadata,
                        'timestamp' => $log->timestamp->toISOString(),
                        'created_at' => $log->created_at->toISOString(),
                        'user' => $log->user ? [
                            'id' => $log->user->id,
                            'name' => $log->user->name,
                            'email' => $log->user->email,
                        ] : null,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);
        } catch (\Exception $e) {
            Log::error('AuditLogController@index failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to retrieve audit logs',
            ], 500);
        }
    }
}
