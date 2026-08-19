<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Controllers;

use App\Modules\Workspace\Actions\CreateWorkspaceRoleAction;
use App\Modules\Workspace\Actions\DeleteWorkspaceRoleAction;
use App\Modules\Workspace\Actions\UpdateWorkspaceRoleAction;
use App\Modules\Workspace\Data\ClientPermission;
use App\Modules\Workspace\Data\WorkspacePermission;
use App\Modules\Workspace\Data\WorkspaceRoleData;
use App\Modules\Workspace\Http\Requests\StoreWorkspaceRoleRequest;
use App\Modules\Workspace\Http\Requests\UpdateWorkspaceRoleRequest;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceRoleController
{
    public function index(Request $request, Workspace $workspace): Response
    {
        $memberCounts = DB::table('workspace_users')
            ->where('workspace_id', $workspace->id)
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return Inertia::render('workspace/settings/RoleManagement', [
            'workspaceId' => $workspace->id,
            'availablePermissions' => WorkspacePermission::values(),
            'availableClientPermissions' => array_map(
                fn (ClientPermission $permission) => [
                    'value' => $permission->value,
                    'label' => $permission->label(),
                    'description' => $permission->description(),
                ],
                ClientPermission::cases(),
            ),
            'clientPermissionDefaults' => ClientPermission::defaults(),
            'canManageRoles' => $request->user()->can('manageRoles', $workspace),
            'systemRoles' => collect(UserRole::cases())
                ->map(fn (UserRole $role) => [
                    'value' => $role->value,
                    'label' => $role->label(),
                    'description' => $role->description(),
                    'member_count' => (int) ($memberCounts[$role->value] ?? 0),
                ])
                ->values(),
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
