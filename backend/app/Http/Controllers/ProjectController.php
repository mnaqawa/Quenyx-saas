<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Project::class, 'project');
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Get all projects where user is either owner or member
            $projects = Project::query()
                ->where(function ($query) use ($user) {
                    // User is owner
                    $query->where('owner_id', $user->id)
                        // OR user has membership
                        ->orWhereHas('memberships', function ($q) use ($user) {
                            $q->where('user_id', $user->id);
                        });
                })
                ->with(['memberships' => function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                }])
                ->latest('updated_at')
                ->get()
                ->map(function (Project $project) use ($user) {
                    // Determine role: owner takes precedence
                    $role = 'owner';
                    if ($project->owner_id !== $user->id) {
                        // User is not owner, get role from membership
                        $membership = $project->memberships->first();
                        $role = $membership ? $membership->role : null;
                    }

                    return [
                        'project' => [
                            'id' => $project->id,
                            'name' => $project->name,
                            'status' => $project->status,
                            'created_at' => $project->created_at->toISOString(),
                            'updated_at' => $project->updated_at->toISOString(),
                        ],
                        'my_role' => $role,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => $projects,
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectController@index failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to retrieve projects',
            ], 500);
        }
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $data = $request->validated();

            $project = Project::create([
                'owner_id' => $user->id,
                'name' => $data['name'],
                'status' => $data['status'] ?? 'active',
            ]);

            return response()->json([
                'success' => true,
                'data' => new ProjectResource($project),
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectController@store failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to create project',
            ], 500);
        }
    }

    public function show(Project $project): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new ProjectResource($project),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => new ProjectResource($project),
        ]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $project->delete();

        return response()->json([
            'success' => true,
        ]);
    }
}
