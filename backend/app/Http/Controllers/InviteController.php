<?php

namespace App\Http\Controllers;

use App\Models\ProjectInvite;
use App\Models\ProjectMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InviteController extends Controller
{
    /**
     * Accept a project invite by token
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Find invite by token
            $invite = ProjectInvite::where('token', $token)->first();

            if (!$invite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invite not found',
                ], 404);
            }

            // Only allow accept if invite.status == 'pending'
            if ($invite->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invite is not pending',
                ], 409);
            }

            // Only allow accept if invite.email matches authenticated user email (case-insensitive)
            if (strtolower($invite->email) !== strtolower($user->email)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This invite is for a different email address',
                ], 403);
            }

            // Check if membership already exists
            $existingMembership = ProjectMembership::where('project_id', $invite->project_id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingMembership) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are already a member of this project',
                ], 409);
            }

            // Create membership and update invite in a transaction
            DB::beginTransaction();
            try {
                // Create ProjectMembership
                $membership = ProjectMembership::create([
                    'project_id' => $invite->project_id,
                    'user_id' => $user->id,
                    'role' => $invite->role,
                ]);

                // Mark invite as accepted
                $invite->update([
                    'status' => 'accepted',
                    'accepted_at' => now(),
                ]);

                DB::commit();

                $membership->load('user:id,name,email');
                $invite->load('project:id,name,status');

                return response()->json([
                    'success' => true,
                    'data' => [
                        'membership' => [
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
                        'project' => [
                            'id' => $invite->project->id,
                            'name' => $invite->project->name,
                            'status' => $invite->project->status,
                        ],
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('InviteController@accept failed', [
                'token' => $token,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Failed to accept invite',
            ], 500);
        }
    }
}
