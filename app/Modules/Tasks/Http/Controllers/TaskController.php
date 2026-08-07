<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Controllers;

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Actions\CreateTaskAction;
use App\Modules\Tasks\Actions\DeleteTaskAction;
use App\Modules\Tasks\Actions\UpdateTaskAction;
use App\Modules\Tasks\Actions\UpdateTaskStatusAction;
use App\Modules\Tasks\Http\Requests\StoreTaskRequest;
use App\Modules\Tasks\Http\Requests\UpdateTaskRequest;
use App\Modules\Tasks\Http\Requests\UpdateTaskStatusRequest;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TaskController
{
    public function store(
        StoreTaskRequest $request,
        Workspace $workspace,
        Project $project,
        CreateTaskAction $action,
    ): RedirectResponse {
        $task = $action->handle($project, $request->toDTO());

        return back()->with('success', "Task \"{$task->title}\" created.");
    }

    public function update(
        UpdateTaskRequest $request,
        Workspace $workspace,
        Project $project,
        Task $task,
        UpdateTaskAction $action,
    ): RedirectResponse {
        $task = $action->handle($task, $request->toDTO());

        return back()->with('success', "Task \"{$task->title}\" updated.");
    }

    public function updateStatus(
        UpdateTaskStatusRequest $request,
        Workspace $workspace,
        Project $project,
        Task $task,
        UpdateTaskStatusAction $action,
    ): RedirectResponse {
        $action->handle($task, $request->toDTO());

        return back()->with('success', 'Task status updated.');
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        Project $project,
        Task $task,
        DeleteTaskAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('delete', $task), 403);

        $title = $task->title;

        $action->handle($task);

        return back()->with('success', "Task \"{$title}\" deleted.");
    }
}
