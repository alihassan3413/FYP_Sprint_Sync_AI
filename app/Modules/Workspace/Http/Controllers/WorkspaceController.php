<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Controllers;

use App\Modules\Workspace\Actions\CreateWorkspaceAction;
use App\Modules\Workspace\Http\Requests\StoreWorkspaceRequest;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceController
{
    public function __construct(private readonly WorkspaceService $service) {}

    public function edit(Workspace $workspace): Response
    {
        return Inertia::render('workspace/settings/index', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'created_at' => $workspace->created_at->toIso8601String(),
            ],
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
}
