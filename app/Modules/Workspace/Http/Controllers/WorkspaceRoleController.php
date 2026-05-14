<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Controllers;

use App\Modules\Workspace\Actions\CreateWorkspaceRoleAction;
use App\Modules\Workspace\Http\Requests\StoreWorkspaceRoleRequest;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class WorkspaceRoleController
{
    public function index() {
        return Inertia::render('workspace/settings/index');
    }

    public function store(
        StoreWorkspaceRoleRequest $request,
        Workspace $workspace,
        CreateWorkspaceRoleAction $action
    ): RedirectResponse {
        $role = $action->handle($workspace, $request->toDTO());

        return back()->with('success', sprintf('Role "%s" created successfully.', $role->name));
    }
}
