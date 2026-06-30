<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * A precomputed bcrypt hash of a random value, used solely to equalize the
     * cost of the login path when no matching user exists (timing-attack /
     * user-enumeration resistance). Never matches any real credential.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$10$tYHLES4jGjiFta.FWxqxA.2f03LdexqejX5lXz95gEwUynrcxYNO6';

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
        // Normalize email before validation (trailing spaces / odd casing break lookups).
        if ($request->has('email') && is_string($request->input('email'))) {
            $request->merge(['email' => trim($request->input('email'))]);
        }

        // Safe login telemetry (never log plaintext credentials).
        $email = (string) $request->input('email', '');
        $maskedEmail = $email !== '' ? preg_replace('/(^.).*(@.*$)/', '$1***$2', $email) : null;
        Log::info('Login request received', [
            'has_email' => $request->has('email'),
            'has_password' => $request->has('password'),
            'email_masked' => $maskedEmail,
            'password_length' => $request->input('password') ? strlen((string) $request->input('password')) : 0,
            'content_type' => $request->header('Content-Type'),
            'request_method' => $request->method(),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $normalizedEmail = strtolower($credentials['email']);
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();

        if ($user === null) {
            // GA HARDENING: timing-attack / user-enumeration resistance. Perform a
            // dummy hash comparison so the "user not found" path costs roughly the
            // same as the "wrong password" path instead of returning immediately.
            Hash::check($credentials['password'], self::DUMMY_PASSWORD_HASH);

            Log::warning('Login failed', [
                'reason' => 'user_not_found',
                'email_masked' => $maskedEmail,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if (!Hash::check($credentials['password'], $user->password)) {
            Log::warning('Login failed', [
                'reason' => 'password_mismatch',
                'email_masked' => $maskedEmail,
                'user_id' => $user->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        try {
            $token = $user->createToken('api')->plainTextToken;
        } catch (\Throwable $e) {
            Log::error('Login token creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Authentication service unavailable. Please try again.',
            ], 503);
        }

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

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user->refresh();
        $projectIds = ProjectMembership::where('user_id', $user->id)->pluck('project_id');
        $activeModules = $this->countActiveModulesForUser($user->id);
        $integrations = DB::table('integration_configurations')
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('project_id')
            ->count();
        $apiCalls30d = 0;
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'api_calls_30d')) {
            $apiCalls30d = (int) ($user->getAttribute('api_calls_30d') ?? 0);
        }

        $preferences = $user->preferences ?? [];
        if (! is_array($preferences)) {
            $preferences = [];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'preferences' => $preferences,
                'stats' => [
                    'active_modules' => $activeModules,
                    'integrations' => $integrations,
                    'api_calls_30d' => $apiCalls30d,
                ],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'preferences' => ['sometimes', 'array'],
            'preferences.theme' => ['sometimes', 'string', 'in:light,dark,system'],
            'preferences.language' => ['sometimes', 'string', 'in:en,ar'],
            'preferences.notifications' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (array_key_exists('preferences', $validated) && \Illuminate\Support\Facades\Schema::hasColumn($user->getTable(), 'preferences')) {
            $current = is_array($user->preferences) ? $user->preferences : [];
            $user->preferences = array_merge($current, $validated['preferences']);
        }
        $user->save();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'preferences' => $user->preferences ?? [],
                'stats' => $this->statsForUser($user),
            ],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        // GA HARDENING: invalidate all OTHER active tokens after a password change
        // so a leaked/old session cannot survive a credential rotation. The current
        // request's token is preserved so the active session is not disrupted.
        $currentTokenId = $request->user()?->currentAccessToken()?->getKey();
        $user->tokens()
            ->when($currentTokenId !== null, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json([
            'success' => true,
            'data' => ['message' => 'Password updated successfully.'],
        ]);
    }

    private function statsForUser(User $user): array
    {
        $projectIds = ProjectMembership::where('user_id', $user->id)->pluck('project_id');
        $activeModules = $this->countActiveModulesForUser($user->id);
        $integrations = DB::table('integration_configurations')
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('project_id')
            ->count();
        $apiCalls30d = 0;
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'api_calls_30d')) {
            $apiCalls30d = (int) ($user->getAttribute('api_calls_30d') ?? 0);
        }
        return [
            'active_modules' => $activeModules,
            'integrations' => $integrations,
            'api_calls_30d' => $apiCalls30d,
        ];
    }

    /**
     * Count total number of modules the user has access to across all their workspaces (real data from entitlements).
     */
    private function countActiveModulesForUser(int $userId): int
    {
        $memberships = ProjectMembership::where('user_id', $userId)->with('project')->get();
        $entitlementService = app(EntitlementService::class);
        $total = 0;
        foreach ($memberships as $membership) {
            $project = $membership->project;
            if (! $project) {
                continue;
            }
            $entitlements = $entitlementService->getEntitlements($project);
            $modulesAllowed = $entitlements['modules_allowed'] ?? [];
            $total += is_array($modulesAllowed) ? count($modulesAllowed) : 0;
        }
        return $total;
    }
}
