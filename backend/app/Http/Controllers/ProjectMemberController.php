<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\ProjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProjectMemberController extends Controller
{
    public function __construct(
        private ProjectAccessService $accessService
    ) {
    }

    /**
     * Get project members
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            // Check if user can view project
            if (!$this->accessService->canViewProject($user, $project)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this project',
                ], 403);
            }

            // Get owner
            $owner = $project->owner;
            $members = $project->members()
                ->with('user:id,name,email')
                ->get()
                ->map(function (ProjectMember $member) {
                    return [
                        'id' => $member->id,
                        'user_id' => $member->user_id,
                        'user' => [
                            'id' => $member->user->id,
                            'name' => $member->user->name,
                            'email' => $member->user->email,
                        ],
                        'role' => $member->role,
                        'created_at' => $member->created_at->toISOString(),
                    ];
                })
                ->values();

            // Include owner in response
            $allMembers = collect([
                [
                    'id' => null,
                    'user_id' => $owner->id,
                    'user' => [
                        'id' => $owner->id,
                        'name' => $owner->name,
                        'email' => $owner->email,
                    ],
                    'role' => 'owner',
                    'created_at' => $project->created_at->toISOString(),
                ],
            ])->merge($members)->values();

            return response()->json([
                'success' => true,
                'data' => $allMembers,
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectMemberController@index failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to retrieve members',
            ], 500);
        }
    }

    /**
     * Add member to project
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            // Check if user can manage project
            if (!$this->accessService->canManageProject($user, $project)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owners and admins can manage members',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email', 'exists:users,email'],
                'role' => ['required', 'string', 'in:admin,member,viewer'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $targetUser = User::where('email', $request->email)->firstOrFail();

            // Prevent adding owner as member
            if ($project->owner_id === $targetUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project owner is already a member',
                ], 400);
            }

            // Check if user is already a member
            $existing = ProjectMember::where('project_id', $project->id)
                ->where('user_id', $targetUser->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is already a member of this project',
                ], 400);
            }

            $member = ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $targetUser->id,
                'role' => $request->role,
            ]);

            $member->load('user:id,name,email');

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $member->id,
                    'user_id' => $member->user_id,
                    'user' => [
                        'id' => $member->user->id,
                        'name' => $member->user->name,
                        'email' => $member->user->email,
                    ],
                    'role' => $member->role,
                    'created_at' => $member->created_at->toISOString(),
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('ProjectMemberController@store failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to add member',
            ], 500);
        }
    }

    /**
     * Update member role
     */
    public function update(Request $request, Project $project, User $user): JsonResponse
    {
        try {
            $currentUser = $request->user();
            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            // Check if current user can manage project
            if (!$this->accessService->canManageProject($currentUser, $project)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owners and admins can manage members',
                ], 403);
            }

            // Prevent changing owner role
            if ($project->owner_id === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change role of project owner',
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'role' => ['required', 'string', 'in:admin,member,viewer'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $member = ProjectMember::where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $member->update(['role' => $request->role]);
            $member->load('user:id,name,email');

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $member->id,
                    'user_id' => $member->user_id,
                    'user' => [
                        'id' => $member->user->id,
                        'name' => $member->user->name,
                        'email' => $member->user->email,
                    ],
                    'role' => $member->role,
                    'created_at' => $member->created_at->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectMemberController@update failed', [
                'project_id' => $project->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to update member',
            ], 500);
        }
    }

    /**
     * Remove member from project
     */
    public function destroy(Request $request, Project $project, User $user): JsonResponse
    {
        try {
            $currentUser = $request->user();
            if (!$currentUser) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            // Check if current user can manage project
            if (!$this->accessService->canManageProject($currentUser, $project)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owners and admins can manage members',
                ], 403);
            }

            // Prevent removing owner
            if ($project->owner_id === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot remove project owner',
                ], 400);
            }

            $member = ProjectMember::where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $member->delete();

            return response()->json([
                'success' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectMemberController@destroy failed', [
                'project_id' => $project->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to remove member',
            ], 500);
        }
    }
}
