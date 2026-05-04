<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Controllers;

use App\Modules\Workspace\Exceptions\WorkspaceException;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceController
{
    public function __construct(private WorkspaceService $service) {}

    /**
     * Switch the authenticated user's current workspace.
     *
     * The service throws a 403 if the user doesn't belong — but we want
     * a domain exception with a code, so we check first.
     */
    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $request->user();

        if (! $this->service->belongsTo($user, $workspace)) {
            throw WorkspaceException::notFound();
        }

        $this->service->switchTo($user, $workspace);

        return back()->with('success', "Switched to {$workspace->name}.");
    }
}
