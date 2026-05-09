<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Workspace\Actions\CreateWorkspaceAction;
use App\Modules\Workspace\Data\WorkspaceData;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Str;
/**
 * AI tool: create a workspace.
 *
 * Minimal-friction version: only asks for the name. Description and
 * template are skipped — they can be added later via the workspace
 * settings if the user wants. The system prompt should also reflect
 * this so the AI doesn't try to volunteer questions.
 */
class CreateWorkspaceTool implements AssistantTool
{
    public function __construct(
        private readonly CreateWorkspaceAction $action,
    ) {}

    public function name(): string
    {
        return 'create_workspace';
    }

    public function description(): string
    {
        return 'Creates a new workspace for the current user. Use this when '
            . 'the user wants to start a new team space, project area, or '
            . 'organizational unit. Only ask for the workspace name — do NOT '
            . 'ask about description, template, or any other settings. After '
            . 'creating, the user will be automatically switched to it.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The workspace display name. 1-50 characters.',
                    'minLength' => 1,
                    'maxLength' => 50,
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

    public function authorize(User $user): bool
    {
        return $user !== null;
    }

    public function execute(array $args, User $user): array
    {
        $name = trim($args['name']);

        // Business rule: prevent duplicate names per user.
        $exists = Workspace::query()
            ->where('owner_id', $user->id)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            return [
                'success' => false,
                'error_code' => 'duplicate_name',
                'error' => "A workspace named '{$name}' already exists.",
            ];
        }

        $workspaceDTO = WorkspaceData::from([
            'name' => $name,
            'slug' => Str::slug($name),
            'settings' => [],
            'is_active' => true,
        ]);
        // Reuse the existing Action — single source of truth.
        // Adjust the call signature to match YOUR CreateWorkspace::handle().
        $workspace = $this->action->handle(
            $workspaceDTO,
            $user,
        );

        // Return shape includes a 'switch_to' field — the frontend reads
        // this and triggers an Inertia visit to the new workspace.
        return [
            'success' => true,
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug ?? null,
            ],
            // Frontend signal: navigate the user to this URL after creation.
            'switch_to' => "/{$workspace->slug}/dashboard",
            'message' => "Workspace '{$workspace->name}' was created. Switching you over now.",
        ];
    }
}
