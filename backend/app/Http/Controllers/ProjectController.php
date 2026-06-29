<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    public function __construct()
    {
        // Only apply authorizeResource to methods that need a project instance
        // For index, we'll handle authorization manually
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

            // Authorize: user can view their projects list
            $this->authorize('viewAny', Project::class);

            // Get all projects where user is either owner or member
            // Use try-catch around the query to catch any database errors
            try {
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
                    ->get();
            } catch (\Exception $queryException) {
                Log::error('Database query failed in ProjectController@index', [
                    'user_id' => $user->id,
                    'error' => $queryException->getMessage(),
                    'trace' => $queryException->getTraceAsString(),
                    'file' => $queryException->getFile(),
                    'line' => $queryException->getLine(),
                ]);
                throw $queryException; // Re-throw to be caught by outer catch
            }

            // Map projects to response format
            $mappedProjects = $projects->map(function (Project $project) use ($user) {
                try {
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
                            'uuid' => $project->uuid,
                            'name' => $project->name ?? '',
                            'status' => $project->status ?? 'active',
                            'created_at' => $project->created_at ? $project->created_at->toISOString() : null,
                            'updated_at' => $project->updated_at ? $project->updated_at->toISOString() : null,
                        ],
                        'my_role' => $role,
                    ];
                } catch (\Exception $e) {
                    // Log error for this specific project but continue processing others
                    Log::warning('Error processing project in index', [
                        'project_id' => $project->id ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    return null;
                }
            })
            ->filter() // Remove null entries
            ->values();

            return response()->json([
                'success' => true,
                'data' => $mappedProjects,
            ]);
        } catch (AuthorizationException $e) {
            // Re-throw authorization exceptions so they're handled by the exception handler
            throw $e;
        } catch (\Exception $e) {
            Log::error('ProjectController@index failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
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

            $this->authorize('create', Project::class);

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

    public function show(Request $request, Project $project): JsonResponse
    {
        try {
            $this->authorize('view', $project);
            
            return response()->json([
                'success' => true,
                'data' => new ProjectResource($project),
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectController@show failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to retrieve project',
            ], 500);
        }
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        try {
            $this->authorize('update', $project);
            
            $project->update($request->validated());

            return response()->json([
                'success' => true,
                'data' => new ProjectResource($project),
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectController@update failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to update project',
            ], 500);
        }
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        try {
            $this->authorize('delete', $project);
            
            $project->delete();

            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectController@destroy failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to delete project',
            ], 500);
        }
    }
}
