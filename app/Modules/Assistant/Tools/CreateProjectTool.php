<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Projects\Actions\CreateProjectAction;
use App\Modules\Projects\Data\StoreProjectData;
use App\Modules\Projects\Models\Project;

final class CreateProjectTool implements AssistantTool
{
    public function __construct(private readonly CreateProjectAction $action) {}

    public function name(): string
    {
        return 'create_project';
    }

    public function description(): string
    {
        return 'Creates a new project in the current workspace. Use this when the user wants to start a new '
            .'project, workstream, or board. Only ask for the project name — the description is optional and '
            .'the board columns are set up automatically. The user becomes the project manager.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The project name.',
                    'minLength' => 2,
                    'maxLength' => 120,
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional short description of what the project covers.',
                    'maxLength' => 2000,
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->workspace !== null
            && $context->user->can('create', [Project::class, $context->workspace]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ToolContext $context): array
    {
        $workspace = $context->workspace;
        $user = $context->user;

        if ($workspace === null) {
            return ['success' => false, 'error_code' => 'no_workspace', 'error' => 'No active workspace is selected.'];
        }

        if (! $user->can('create', [Project::class, $workspace])) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to create projects in {$workspace->name}.",
            ];
        }

        $name = trim((string) $args['name']);

        $duplicate = $workspace->projects()->where('name', $name)->exists();

        if ($duplicate) {
            return [
                'success' => false,
                'error_code' => 'duplicate_name',
                'error' => "A project named '{$name}' already exists in {$workspace->name}.",
            ];
        }

        $project = $this->action->handle($workspace, StoreProjectData::from([
            'name' => $name,
            'description' => isset($args['description']) ? trim((string) $args['description']) : null,
        ]), $user);

        return [
            'success' => true,
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
            ],
            'url' => route('workspace.projects.show', [
                'workspace' => $workspace->slug,
                'project' => $project->id,
            ]),
            'message' => "Created project '{$project->name}' in {$workspace->name}. You are its manager.",
        ];
    }
}
