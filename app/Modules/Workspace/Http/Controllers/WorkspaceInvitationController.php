<?php

namespace App\Modules\Workspace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workspace\Actions\CreateWorkspaceInvitationAction;
use App\Modules\Workspace\Http\Requests\StoreWorkspaceInvitationRequest;

final class WorkspaceInvitationController extends Controller
{
    public function store (
        StoreWorkspaceInvitationRequest $request,
        CreateWorkspaceInvitationAction $createWorkspaceInvitationAction,
    ) {
        $workspace = $request->user()->activeWorkspaceOrFail();

        $createWorkspaceInvitationAction->handle($workspace, $request->user(), $request->toDTO());

        return back()->with('success', 'Invitation sent successfully.');
    }
}
