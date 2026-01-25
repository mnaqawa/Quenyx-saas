<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Project;
use App\Models\ProjectMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * Register a new user and create their default workspace
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
                'workspace_name' => ['nullable', 'string', 'max:120'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            // Create user and workspace in a transaction
            DB::beginTransaction();
            try {
                // Create user
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                ]);

                // Create default workspace (Project)
                $workspaceName = $validated['workspace_name'] ?? ($user->name ? "{$user->name}'s Workspace" : 'My Workspace');
                $project = Project::create([
                    'owner_id' => $user->id,
                    'name' => $workspaceName,
                    'status' => 'active',
                ]);

                // Create ProjectMembership with owner role
                ProjectMembership::create([
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                ]);

                DB::commit();

                // Create token for immediate authentication
                $token = $user->createToken('api')->plainTextToken;

                return response()->json([
                    'success' => true,
                    'data' => [
                        'token' => $token,
                        'user' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                        ],
                        'workspace' => [
                            'id' => $project->id,
                            'name' => $project->name,
                        ],
                    ],
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('AuthController@register failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Registration failed',
            ], 500);
        }
    }

    public function login(Request $request): JsonResponse
    {
        // Log request data for debugging
        Log::info('Login request received', [
            'has_email' => $request->has('email'),
            'has_password' => $request->has('password'),
            'email_value' => $request->input('email'),
            'password_length' => $request->input('password') ? strlen($request->input('password')) : 0,
            'content_type' => $request->header('Content-Type'),
            'request_method' => $request->method(),
            'all_input' => $request->all(),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $user->createToken('api')->plainTextToken;
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Logged out.',
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user->name = $validated['name'];
        $user->save();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
