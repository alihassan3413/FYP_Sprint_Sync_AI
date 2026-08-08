<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Controllers;

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Actions\CreateTaskCommentAction;
use App\Modules\Tasks\Actions\DeleteTaskCommentAction;
use App\Modules\Tasks\Http\Requests\StoreTaskCommentRequest;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TaskCommentController
{
    public function store(
        StoreTaskCommentRequest $request,
        Workspace $workspace,
        Project $project,
        Task $task,
        CreateTaskCommentAction $action,
    ): RedirectResponse {
        $action->handle($task, $request->user(), $request->string('body')->trim()->toString());

        return back();
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        Project $project,
        Task $task,
        TaskComment $comment,
        DeleteTaskCommentAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('delete', $comment), 403);

        $action->handle($comment);

        return back();
    }
}
