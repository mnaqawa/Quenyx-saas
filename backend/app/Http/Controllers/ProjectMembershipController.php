<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectInvite;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ProjectMembershipController extends Controller
{
    /**
     * Get project memberships and invites
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        try {
            $this->authorize('viewAny', [ProjectMembership::class, $project]);

            // Get owner
            $owner = $project->owner;
            $memberships = $project->memberships()
                ->with('user:id,name,email')
                ->get()
                ->map(function (ProjectMembership $membership) {
                    return [
                        'id' => $membership->id,
                        'user_id' => $membership->user_id,
                        'user' => [
                            'id' => $membership->user->id,
                            'name' => $membership->user->name,
                            'email' => $membership->user->email,
                        ],
                        'role' => $membership->role,
                        'created_at' => $membership->created_at->toISOString(),
                    ];
                })
                ->values();

            // Get invites (include token for owner/admin)
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }
            
            $userMembership = $project->memberships()
                ->where('user_id', $user->id)
                ->first();
            $isOwnerOrAdmin = $project->owner_id === $user->id || ($userMembership && in_array($userMembership->role, ['admin'], true));
            
            $invites = $project->invites()
                ->with('invitedBy:id,name')
                ->get()
                ->map(function (ProjectInvite $invite) use ($isOwnerOrAdmin) {
                    return [
                        'id' => $invite->id,
                        'email' => $invite->email,
                        'role' => $invite->role,
                        'status' => $invite->status,
                        'token' => $isOwnerOrAdmin ? $invite->token : null, // Only include token for owner/admin
                        'invited_by' => [
                            'id' => $invite->invitedBy->id,
                            'name' => $invite->invitedBy->name,
                        ],
                        'created_at' => $invite->created_at->toISOString(),
                        'expires_at' => $invite->expires_at?->toISOString(),
                    ];
                })
                ->values();

            // Include owner as first row; dedupe: exclude membership rows for owner so they are not duplicated
            $ownerRow = [
                'id' => null,
                'user_id' => $owner->id,
                'user' => [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                ],
                'role' => 'owner',
                'created_at' => $project->created_at->toISOString(),
            ];
            $otherMembers = $memberships->filter(fn ($m) => (int) $m['user_id'] !== (int) $project->owner_id)->values();
            $allMembers = collect([$ownerRow])->merge($otherMembers)->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'memberships' => $allMembers,
                    'invites' => $invites,
                ],
            ]);
        } catch (AuthorizationException $e) {
            // Re-throw authorization exceptions so they're handled by the exception handler
            throw $e;
        } catch (\Exception $e) {
            Log::error('ProjectMembershipController@index failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to retrieve memberships',
            ], 500);
        }
    }

    /**
     * Create invite
     */
    public function invite(Request $request, Project $project): JsonResponse
    {
        try {
            $this->authorize('create', [ProjectMembership::class, $project]);

            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email'],
                'role' => ['required', 'string', 'in:admin,member,viewer'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $normalizedEmail = strtolower(trim((string) $request->email));

            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Check if user already has membership
            $existingMembership = $project->memberships()
                ->whereHas('user', function ($query) use ($normalizedEmail) {
                    $query->whereRaw('LOWER(email) = ?', [$normalizedEmail]);
                })
                ->first();

            if ($existingMembership) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is already a member of this project',
                ], 400);
            }

            // Check if owner
            if (strtolower((string) $project->owner->email) === $normalizedEmail) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project owner is already a member',
                ], 400);
            }

            // Create invite with secure, unique token
            // Generate token and ensure uniqueness
            do {
                $token = bin2hex(random_bytes(32));
            } while (ProjectInvite::where('token', $token)->exists());

            $invite = ProjectInvite::create([
                'project_id' => $project->id,
                'email' => $normalizedEmail,
                'role' => $request->role,
                'invited_by_user_id' => $user->id,
                'status' => 'pending',
                'token' => $token,
                'expires_at' => now()->addDays(7),
            ]);

            $invite->load('invitedBy:id,name');
            $inviteUrl = rtrim((string) config('app.url'), '/') . '/invites/accept?token=' . urlencode($token);
            $emailSent = false;

            try {
                Mail::raw(
                    "You have been invited to join workspace '{$project->name}' on Quenyx.\n\n"
                    . "Role: {$invite->role}\n"
                    . "Accept invite: {$inviteUrl}\n\n"
                    . "This invite expires at: " . ($invite->expires_at?->toIso8601String() ?? 'N/A') . "\n",
                    function ($message) use ($invite, $project): void {
                        $message->to($invite->email)
                            ->subject("Quenyx Workspace Invite: {$project->name}");
                    }
                );
                $emailSent = true;
            } catch (\Throwable $mailError) {
                Log::warning('ProjectMembershipController@invite mail send failed', [
                    'project_id' => $project->id,
                    'invite_id' => $invite->id,
                    'email' => $invite->email,
                    'error' => $mailError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $invite->id,
                    'email' => $invite->email,
                    'role' => $invite->role,
                    'status' => $invite->status,
                    'email_sent' => $emailSent,
                    'invite_url' => $inviteUrl,
                    'invited_by' => [
                        'id' => $invite->invitedBy->id,
                        'name' => $invite->invitedBy->name,
                    ],
                    'created_at' => $invite->created_at->toISOString(),
                    'expires_at' => $invite->expires_at->toISOString(),
                ],
            ], 201);
        } catch (AuthorizationException $e) {
            // Re-throw authorization exceptions so they're handled by the exception handler
            throw $e;
        } catch (\Exception $e) {
            Log::error('ProjectMembershipController@invite failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to create invite',
            ], 500);
        }
    }

    /**
     * Add member directly (by email, if user exists)
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        try {
            $this->authorize('create', [ProjectMembership::class, $project]);

            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email'],
                'role' => ['required', 'string', 'in:admin,member,viewer'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $normalizedEmail = strtolower(trim((string) $request->email));
            $targetUser = User::query()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->first();

            if (!$targetUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => [
                        'email' => ['No registered user found with this email. Use Invite for new users.'],
                    ],
                ], 422);
            }

            // Prevent adding owner as member
            if ($project->owner_id === $targetUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project owner is already a member',
                ], 400);
            }

            // Check if user is already a member
            $existing = $project->memberships()
                ->where('user_id', $targetUser->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is already a member of this project',
                ], 400);
            }

            $membership = ProjectMembership::create([
                'project_id' => $project->id,
                'user_id' => $targetUser->id,
                'role' => $request->role,
            ]);

            $membership->load('user:id,name,email');

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'user' => [
                        'id' => $membership->user->id,
                        'name' => $membership->user->name,
                        'email' => $membership->user->email,
                    ],
                    'role' => $membership->role,
                    'created_at' => $membership->created_at->toISOString(),
                ],
            ], 201);
        } catch (AuthorizationException $e) {
            // Re-throw authorization exceptions so they're handled by the exception handler
            throw $e;
        } catch (\Exception $e) {
            Log::error('ProjectMembershipController@store failed', [
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
     * Update membership role
     */
    public function update(Request $request, Project $project, ProjectMembership $membership): JsonResponse
    {
        try {
            // Ensure membership belongs to project
            if ($membership->project_id !== $project->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Membership not found',
                ], 404);
            }

            $this->authorize('update', $membership);

            $validator = Validator::make($request->all(), [
                'role' => ['required', 'string', 'in:owner,admin,member,viewer'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Only owner can promote to owner
            if ($request->role === 'owner' && $project->owner_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only project owner can promote to owner',
                ], 403);
            }

            $oldRole = $membership->role;
            $membership->update(['role' => $request->role]);
            $membership->load('user:id,name,email');

            if ($oldRole !== $membership->role && Schema::hasTable('audit_logs')) {
                AuditLog::create([
                    'user_id' => $request->user()?->id,
                    'project_id' => $project->id,
                    'action' => 'workspace_membership_role_changed',
                    'metadata' => [
                        'membership_id' => $membership->id,
                        'target_user_id' => $membership->user_id,
                        'target_email' => $membership->user?->email,
                        'old_role' => $oldRole,
                        'new_role' => $membership->role,
                        'changed_via' => 'workspace_memberships_update',
                    ],
                    'timestamp' => now(),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'user' => [
                        'id' => $membership->user->id,
                        'name' => $membership->user->name,
                        'email' => $membership->user->email,
                    ],
                    'role' => $membership->role,
                    'created_at' => $membership->created_at->toISOString(),
                ],
            ]);
        } catch (AuthorizationException $e) {
            // Re-throw authorization exceptions so they're handled by the exception handler
            throw $e;
        } catch (\Exception $e) {
            Log::error('ProjectMembershipController@update failed', [
                'project_id' => $project->id,
                'membership_id' => $membership->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to update membership',
            ], 500);
        }
    }

    /**
     * Remove membership
     */
    public function destroy(Request $request, Project $project, ProjectMembership $membership): JsonResponse
    {
        try {
            // Ensure membership belongs to project
            if ($membership->project_id !== $project->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Membership not found',
                ], 404);
            }

            $this->authorize('delete', $membership);

            $membership->delete();

            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        } catch (AuthorizationException $e) {
            // Re-throw authorization exceptions so they're handled by the exception handler
            throw $e;
        } catch (\Exception $e) {
            Log::error('ProjectMembershipController@destroy failed', [
                'project_id' => $project->id,
                'membership_id' => $membership->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to remove membership',
            ], 500);
        }
    }
}
