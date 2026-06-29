<?php

namespace App\Services\Ai\Workspace;

use App\Models\Ai\AiPromptTemplate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sprint 20 — workspace prompt template CRUD. Workspace-scoped, UUID-addressed, audited.
 */
class AiPromptTemplateService
{
    public function __construct(private readonly AiWorkspaceAuditLogger $audit) {}

    /**
     * @return Collection<int, AiPromptTemplate>
     */
    public function list(Project $project): Collection
    {
        return AiPromptTemplate::query()
            ->where('project_id', $project->id)
            ->orderBy('name')
            ->get();
    }

    public function findForProject(Project $project, string $uuid): ?AiPromptTemplate
    {
        return AiPromptTemplate::query()
            ->where('project_id', $project->id)
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, User $user, array $data): AiPromptTemplate
    {
        $template = AiPromptTemplate::create([
            'project_id' => $project->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'body' => $data['body'],
            'variables' => $data['variables'] ?? null,
            'is_shared' => $data['is_shared'] ?? true,
        ]);

        $this->audit->record($user, $project, 'ai_prompt_template_created', [
            'template_uuid' => $template->uuid,
            'name' => $template->name,
        ]);

        return $template;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, User $user, AiPromptTemplate $template, array $data): AiPromptTemplate
    {
        $template->fill(array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'body' => $data['body'] ?? null,
        ], static fn ($v) => $v !== null));

        if (array_key_exists('variables', $data)) {
            $template->variables = $data['variables'];
        }
        if (array_key_exists('is_shared', $data)) {
            $template->is_shared = (bool) $data['is_shared'];
        }

        $template->updated_by = $user->id;
        $template->save();

        $this->audit->record($user, $project, 'ai_prompt_template_updated', [
            'template_uuid' => $template->uuid,
            'name' => $template->name,
        ]);

        return $template;
    }

    public function delete(Project $project, User $user, AiPromptTemplate $template): void
    {
        $uuid = $template->uuid;
        $name = $template->name;
        $template->delete();

        $this->audit->record($user, $project, 'ai_prompt_template_deleted', [
            'template_uuid' => $uuid,
            'name' => $name,
        ]);
    }
}
