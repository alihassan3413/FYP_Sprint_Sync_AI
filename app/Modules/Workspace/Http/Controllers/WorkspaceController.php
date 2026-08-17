<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Controllers;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Workspace\Actions\CreateWorkspaceAction;
use App\Modules\Workspace\Actions\DeleteWorkspaceAction;
use App\Modules\Workspace\Actions\UpdateWorkspaceAction;
use App\Modules\Workspace\Http\Requests\StoreWorkspaceRequest;
use App\Modules\Workspace\Http\Requests\UpdateWorkspaceRequest;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceController
{
    public function __construct(private readonly WorkspaceService $service) {}

    public function edit(Request $request, Workspace $workspace): Response
    {
        return Inertia::render('workspace/settings/index', [
            'workspaceProfile' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'created_at' => $workspace->created_at->toIso8601String(),
            ],
            'canViewAuditLog' => $request->user()->can('viewAny', [AuditLog::class, $workspace]),
            'canUpdateWorkspace' => $request->user()->can('update', $workspace),
            'canDeleteWorkspace' => $request->user()->can('delete', $workspace),
            'canManageMembers' => $request->user()->can('manageMembers', $workspace),
            'canInviteMembers' => $request->user()->can('invite', $workspace),
        ]);
    }

    public function store(StoreWorkspaceRequest $request, CreateWorkspaceAction $action): RedirectResponse
    {
        $workspace = $action->handle($request->toDTO(), $request->user());

        return to_route('dashboard', ['workspace' => $workspace->slug])
            ->with('success', "Workspace \"{$workspace->name}\" created.");
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->service->switchTo($request->user(), $workspace);

        return to_route('dashboard', ['workspace' => $workspace->slug])
            ->with('success', "Switched to {$workspace->name}.");
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace, UpdateWorkspaceAction $action): RedirectResponse
    {
        $workspace = $action->handle($workspace, $request->toDTO(), $request->user());

        return to_route('workspace.settings', ['workspace' => $workspace->slug])
            ->with('success', 'Workspace updated.');
    }

    public function destroy(Request $request, Workspace $workspace, DeleteWorkspaceAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('delete', $workspace), 403);

        $name = $workspace->name;

        $action->handle($workspace, $request->user());

        $user = $request->user()->fresh();
        $fallback = $this->service->currentFor($user) ?? $user->workspaces()->first();

        if ($fallback === null) {
            return to_route('login')->with('success', "Workspace \"{$name}\" deleted.");
        }

        if ($user->current_workspace_id !== $fallback->id) {
            $this->service->switchTo($user, $fallback);
        }

        return to_route('dashboard', ['workspace' => $fallback->slug])
            ->with('success', "Workspace \"{$name}\" deleted.");
    }
}
