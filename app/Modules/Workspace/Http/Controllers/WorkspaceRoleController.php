<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Controllers;

use App\Modules\Workspace\Actions\CreateWorkspaceRoleAction;
use App\Modules\Workspace\Actions\DeleteWorkspaceRoleAction;
use App\Modules\Workspace\Actions\UpdateWorkspaceRoleAction;
use App\Modules\Workspace\Data\WorkspaceRoleData;
use App\Modules\Workspace\Http\Requests\StoreWorkspaceRoleRequest;
use App\Modules\Workspace\Http\Requests\UpdateWorkspaceRoleRequest;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\Modules\Workspace\Support\WorkspacePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceRoleController
{
    public function index(Request $request, Workspace $workspace): Response
    {
        return Inertia::render('workspace/settings/RoleManagement', [
            'workspaceId' => $workspace->id,
            'availablePermissions' => WorkspacePermission::values(),
            'canManageRoles' => $request->user()->can('manageRoles', $workspace),
            'roles' => $workspace->roles()
                ->withCount('members')
                ->orderBy('name')
                ->get()
                ->map(WorkspaceRoleData::fromModel(...))
                ->values(),
        ]);
    }

    public function store(
        StoreWorkspaceRoleRequest $request,
        Workspace $workspace,
        CreateWorkspaceRoleAction $action,
    ): RedirectResponse {
        $role = $action->handle($workspace, $request->toDTO());

        return back()->with('success', "Role \"{$role->name}\" created.");
    }

    public function update(
        UpdateWorkspaceRoleRequest $request,
        Workspace $workspace,
        WorkspaceRole $role,
        UpdateWorkspaceRoleAction $action,
    ): RedirectResponse {
        $action->handle($role, $request->toDTO());

        return back()->with('success', "Role \"{$role->name}\" updated.");
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        WorkspaceRole $role,
        DeleteWorkspaceRoleAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('delete', $role), 403);

        $name = $role->name;
        $action->handle($role);

        return back()->with('success', "Role \"{$name}\" deleted.");
    }
}
