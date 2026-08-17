<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class ListProjectsTool implements AssistantTool
{
    private const MAX_RESULTS = 25;

    private const DESCRIPTION_LIMIT = 160;

    public function name(): string
    {
        return 'list_projects';
    }

    public function description(): string
    {
        return 'Lists the projects the user can access in the current workspace, with their IDs. '
            .'Use this whenever the user mentions a project by name, says "my projects", "this project", '
            .'or asks which projects exist. Always call this to obtain a real project ID — never guess one. '
            .'Pass search to narrow the list when the user names a specific project.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => [
                    'type' => 'string',
                    'description' => 'Optional case-insensitive filter matched against the project name.',
                    'maxLength' => 60,
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->workspace !== null;
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
            return ['success' => false, 'message' => 'No active workspace is selected.'];
        }

        $query = $workspace->accessibleProjectsFor($user)
            ->with(['members' => fn (BelongsToMany $members) => $members->whereKey($user->id)]);

        $search = isset($args['search']) ? trim((string) $args['search']) : '';

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $total = (clone $query)->count();

        $projects = $query->orderBy('name')->limit(self::MAX_RESULTS)->get();

        return [
            'success' => true,
            'workspace' => ['id' => $workspace->id, 'name' => UntrustedText::inline($workspace->name)],
            'total' => $total,
            'returned' => $projects->count(),
            'truncated' => $total > $projects->count(),
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => UntrustedText::inline($project->name),
                'description' => $this->shortDescription($project->description),
                'project_role' => $project->members->first()?->pivot->role,
            ])->all(),
        ];
    }

    private function shortDescription(?string $description): ?string
    {
        $description = $description === null ? '' : trim($description);

        if ($description === '') {
            return null;
        }

        return UntrustedText::block($description, self::DESCRIPTION_LIMIT);
    }
}
