<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Workspace\Actions\CreateWorkspaceAction;
use App\Modules\Workspace\Exceptions\WorkspaceException;
use App\Modules\Workspace\Models\Workspace;

final class CreateWorkspaceTool implements AssistantTool
{
    public function __construct(private readonly CreateWorkspaceAction $action) {}

    public function name(): string
    {
        return 'create_workspace';
    }

    public function description(): string
    {
        return 'Creates a new workspace for the current user. Use this when the user wants to start a new team '
            .'space, project area, or organizational unit. Only ask for the workspace name — never ask about '
            .'description, template, or other settings. The user is switched to the new workspace automatically.';
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
                    'description' => 'The workspace display name.',
                    'minLength' => 2,
                    'maxLength' => 60,
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
        return $context->user->exists;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ToolContext $context): array
    {
        $user = $context->user;
        $name = trim((string) $args['name']);

        $duplicate = Workspace::query()
            ->where('owner_id', $user->id)
            ->where('name', $name)
            ->exists();

        if ($duplicate) {
            return [
                'success' => false,
                'error_code' => 'duplicate_name',
                'error' => "A workspace named '{$name}' already exists.",
            ];
        }

        try {
            $workspace = $this->action->handleForUser($user, $name);
        } catch (WorkspaceException $e) {
            return [
                'success' => false,
                'error_code' => $e->getErrorCode(),
                'error' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'switch_to' => "/{$workspace->slug}/dashboard",
            'message' => "Workspace '{$workspace->name}' was created. Switching you over now.",
        ];
    }
}
