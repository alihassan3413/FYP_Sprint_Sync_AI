<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Controllers;

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Actions\CreateBoardColumnAction;
use App\Modules\Tasks\Actions\DeleteBoardColumnAction;
use App\Modules\Tasks\Actions\ReorderBoardColumnsAction;
use App\Modules\Tasks\Http\Requests\ReorderBoardColumnsRequest;
use App\Modules\Tasks\Http\Requests\StoreBoardColumnRequest;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BoardColumnController
{
    public function store(
        StoreBoardColumnRequest $request,
        Workspace $workspace,
        Project $project,
        CreateBoardColumnAction $action,
    ): RedirectResponse {
        $column = $action->handle($project, $request->string('name')->trim()->toString(), $request->user());

        return back()->with('success', "Column \"{$column->name}\" added.");
    }

    public function reorder(
        ReorderBoardColumnsRequest $request,
        Workspace $workspace,
        Project $project,
        ReorderBoardColumnsAction $action,
    ): RedirectResponse {
        $action->handle($project, $request->orderedColumnIds(), $request->user());

        return back()->with('success', 'Columns reordered.');
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        Project $project,
        BoardColumn $boardColumn,
        DeleteBoardColumnAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('delete', $boardColumn), 403);

        if ($boardColumn->is_default) {
            return back()->with('error', 'Default columns cannot be deleted.');
        }

        if ($boardColumn->tasks()->exists()) {
            return back()->with('error', 'Move all tasks out of this column before deleting it.');
        }

        $name = $boardColumn->name;

        $action->handle($boardColumn, $request->user());

        return back()->with('success', "Column \"{$name}\" deleted.");
    }
}
