<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collaboration;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\Collaboration\CollaborationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sprint 24 — the reusable Collaboration API. Platform-wide (available to any workspace member, no
 * per-module entitlement) so EVERY module reuses the same comments/mentions/watchers/assignments on any
 * entity. Workspace resolved from a REQUIRED `workspace` UUID; `accessAi` (workspace membership) gates
 * access; `{ success, data }` envelope. Rate limiting + Sanctum auth applied at the route level.
 */
class CollaborationController extends Controller
{
    public function __construct(
        private readonly AiWorkspaceContextResolver $context,
        private readonly CollaborationService $collaboration,
    ) {}

    private function workspace(Request $request): Project
    {
        $project = $this->context->resolve($request);
        $this->authorize('accessAi', $project);

        return $project;
    }

    private function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    public function thread(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'entity_type' => ['required', Rule::in(CollaborationService::ENTITY_TYPES)],
            'entity_uuid' => 'required|uuid',
        ]);

        return $this->ok($this->collaboration->thread($project, (string) $data['entity_type'], (string) $data['entity_uuid']));
    }

    public function comment(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'entity_type' => ['required', Rule::in(CollaborationService::ENTITY_TYPES)],
            'entity_uuid' => 'required|uuid',
            'body' => 'required|string|max:5000',
            'mentions' => 'nullable|array',
            'mentions.*' => 'uuid',
        ]);

        $comment = $this->collaboration->comment(
            $project,
            $request->user(),
            (string) $data['entity_type'],
            (string) $data['entity_uuid'],
            (string) $data['body'],
            $data['mentions'] ?? [],
        );

        return $this->ok([
            'uuid' => $comment->uuid,
            'thread' => $this->collaboration->thread($project, (string) $data['entity_type'], (string) $data['entity_uuid']),
        ], 201);
    }

    public function addParticipant(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'entity_type' => ['required', Rule::in(CollaborationService::ENTITY_TYPES)],
            'entity_uuid' => 'required|uuid',
            'user_uuid' => 'required|uuid',
            'role' => ['required', Rule::in(CollaborationService::ROLES)],
        ]);

        $user = User::where('uuid', $data['user_uuid'])->firstOrFail();
        $this->collaboration->addParticipant($project, $user, (string) $data['entity_type'], (string) $data['entity_uuid'], (string) $data['role']);

        return $this->ok($this->collaboration->thread($project, (string) $data['entity_type'], (string) $data['entity_uuid']), 201);
    }

    public function removeParticipant(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'entity_type' => ['required', Rule::in(CollaborationService::ENTITY_TYPES)],
            'entity_uuid' => 'required|uuid',
            'user_uuid' => 'required|uuid',
            'role' => ['required', Rule::in(CollaborationService::ROLES)],
        ]);

        $user = User::where('uuid', $data['user_uuid'])->firstOrFail();
        $this->collaboration->removeParticipant($project, $user, (string) $data['entity_type'], (string) $data['entity_uuid'], (string) $data['role']);

        return $this->ok($this->collaboration->thread($project, (string) $data['entity_type'], (string) $data['entity_uuid']));
    }
}
