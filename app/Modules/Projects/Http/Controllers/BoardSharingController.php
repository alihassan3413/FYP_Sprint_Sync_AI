<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Modules\Projects\Actions\DecideBoardAccessRequestAction;
use App\Modules\Projects\Actions\UpdateBoardSharingAction;
use App\Modules\Projects\Data\BoardShareAccess;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectAccessRequest;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The project's Share dialog: who the link lets in, and the people waiting
 * to add tasks. Workspace admins and project managers only.
 */
final class BoardSharingController
{
    /**
     * @return array{access: string|null, url: string|null, requests: list<array{id: int, name: string, email: string, requested_at: string}>}
     */
    public static function settings(Project $project): array
    {
        return [
            'access' => $project->share_access?->value,
            'url' => $project->share_token === null ? null : route('shared-board.show', $project->share_token),
            'requests' => $project->accessRequests()->pending()->with('user:id,name,email')->oldest()->get()
                ->map(fn (ProjectAccessRequest $request) => [
                    'id' => $request->id,
                    'name' => $request->user->name,
                    'email' => $request->user->email,
                    'requested_at' => $request->updated_at->toIso8601String(),
                ])->values()->all(),
        ];
    }

    public function update(Request $request, Workspace $workspace, Project $project, UpdateBoardSharingAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('update', $project), 403);

        $validated = $request->validate([
            'access' => ['nullable', Rule::enum(BoardShareAccess::class)],
            'regenerate' => ['sometimes', 'boolean'],
        ]);

        $access = BoardShareAccess::tryFrom((string) ($validated['access'] ?? ''));
        $action->handle($project, $access, (bool) ($validated['regenerate'] ?? false), $request->user());

        return back()->with('success', match (true) {
            $access === null => 'Sharing is off. The link no longer opens the board.',
            $request->boolean('regenerate') => 'New link created. The old link no longer works.',
            default => 'Sharing updated.',
        });
    }

    public function decide(Request $request, Workspace $workspace, Project $project, int $accessRequest, DecideBoardAccessRequestAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('update', $project), 403);
        $validated = $request->validate(['decision' => ['required', Rule::in(['approve', 'decline'])]]);
        $model = $project->accessRequests()->with(['user', 'project'])->findOrFail($accessRequest);

        $approve = $validated['decision'] === 'approve';
        $action->handle($model, $approve, $request->user());

        return back()->with('success', $approve ? "{$model->user->name} can now add tasks." : 'Request declined.');
    }
}
