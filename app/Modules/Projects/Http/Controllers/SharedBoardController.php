<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Modules\Projects\Actions\JoinSharedBoardAction;
use App\Modules\Projects\Actions\RequestBoardAccessAction;
use App\Modules\Projects\Data\BoardShareAccess;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectAccessRequest;
use App\Modules\Tasks\Models\Task;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The board behind a share link. Anyone with the link sees it read-only:
 * lists and cards, never comments, attachments, time or people's emails.
 * Adding tasks needs an account: with an "add" link you join straight away,
 * with a "view" link you ask and a manager decides.
 */
final class SharedBoardController
{
    public function show(Request $request, string $token): Response
    {
        $project = $this->sharedProject($token);

        return Inertia::render('shared/board', [
            'token' => $token,
            'project' => ['name' => $project->name, 'description' => $project->description, 'workspace' => $project->workspace->name],
            'access' => $project->share_access->value,
            'columns' => $project->boardColumns()->orderBy('position')->get(['id', 'name', 'is_done', 'is_default', 'position'])->values(),
            'tasks' => $project->tasks()->with('assignee:id,name')->latest()->get()
                ->map(fn (Task $task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'board_column_id' => $task->board_column_id,
                    'due_date' => $task->due_date?->toDateString(),
                    'assignee_name' => $task->assignee?->name,
                ])->values(),
            'viewer' => $viewer = $this->viewerState($request, $project),
            /* Only for people who are already in: the board inside SprintSync. */
            'projectUrl' => $viewer === 'can_add' ? route('workspace.projects.show', ['workspace' => $project->workspace->slug, 'project' => $project->id, 'new_task' => 1]) : null,
        ]);
    }

    /** guest | can_add | pending | approved | declined | can_request */
    private function viewerState(Request $request, Project $project): string
    {
        $user = $request->user();

        return match (true) {
            $user === null => 'guest',
            $user->can('create', [Task::class, $project]) => 'can_add',
            default => ProjectAccessRequest::query()->where('project_id', $project->id)->where('user_id', $user->id)->value('status') ?? 'can_request',
        };
    }

    /** Behind auth: a signed-out visitor comes back here after signing in or registering. */
    public function signIn(string $token): RedirectResponse
    {
        $this->sharedProject($token);

        return to_route('shared-board.show', $token);
    }

    public function join(Request $request, string $token, JoinSharedBoardAction $action): RedirectResponse
    {
        $project = $this->sharedProject($token);
        abort_unless($project->share_access === BoardShareAccess::Add, 403);

        $action->handle($project, $request->user(), $request->user());

        if (! $project->workspace->userHasAtLeast($request->user(), UserRole::ADMIN)) {
            $request->user()->forceFill(['current_workspace_id' => $project->workspace_id])->save();
        }

        return redirect()->route('workspace.projects.show', ['workspace' => $project->workspace->slug, 'project' => $project->id, 'new_task' => 1])
            ->with('success', "You can now add tasks to {$project->name}.");
    }

    public function requestAccess(Request $request, string $token, RequestBoardAccessAction $action): RedirectResponse
    {
        $project = $this->sharedProject($token);

        if ($request->user()->can('create', [Task::class, $project])) {
            return back();
        }

        $action->handle($project, $request->user());

        return back()->with('success', 'Request sent. You will be able to add tasks once a manager approves it.');
    }

    private function sharedProject(string $token): Project
    {
        return Project::query()->with('workspace')->where('share_token', $token)->whereNotNull('share_access')->firstOr(fn () => abort(404));
    }
}
