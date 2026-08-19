<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Controllers;

use App\Models\User;
use App\Modules\Workspace\Actions\AcceptWorkspaceInviteLinkAction;
use App\Modules\Workspace\Actions\GenerateWorkspaceInviteLinkAction;
use App\Modules\Workspace\Actions\RevokeWorkspaceInviteLinkAction;
use App\Modules\Workspace\Http\Requests\JoinWorkspaceByLinkRequest;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInviteLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceInviteLinkController
{
    public function store(Request $request, Workspace $workspace, GenerateWorkspaceInviteLinkAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('invite', $workspace), 403);

        $action->handle($workspace, $request->user());

        return back()->with('success', 'Invite link generated.');
    }

    public function destroy(Request $request, Workspace $workspace, RevokeWorkspaceInviteLinkAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('invite', $workspace), 403);

        $action->handle($workspace, $request->user());

        return back()->with('success', 'Invite link revoked.');
    }

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $link = $this->findLink($token);

        if ($link->isRevoked()) {
            return to_route('login')->with('error', 'This invite link is no longer active.');
        }

        if ($link->isExpired()) {
            return to_route('login')->with('error', 'This invite link has expired.');
        }

        $user = Auth::user();

        if ($user !== null && $link->workspace->hasMember($user)) {
            return to_route('dashboard', ['workspace' => $link->workspace->slug])
                ->with('info', "You are already a member of {$link->workspace->name}.");
        }

        return Inertia::render('workspace/invitations/Join', [
            'token' => $token,
            'requiresRegistration' => $user === null,
            'workspace' => ['name' => $link->workspace->name],
            'expiresAt' => $link->expires_at->toIso8601String(),
        ]);
    }

    public function join(
        string $token,
        JoinWorkspaceByLinkRequest $request,
        AcceptWorkspaceInviteLinkAction $action,
    ): RedirectResponse {
        $link = $this->findLink($token);
        $user = $request->user() ?? $this->registerJoiner($request);

        $action->handle($link, $user);

        return to_route('dashboard', ['workspace' => $link->workspace->slug])
            ->with('success', "You have joined {$link->workspace->name}.");
    }

    private function findLink(string $token): WorkspaceInviteLink
    {
        return WorkspaceInviteLink::query()
            ->with('workspace')
            ->where('token', $token)
            ->firstOrFail();
    }

    private function registerJoiner(JoinWorkspaceByLinkRequest $request): User
    {
        $user = DB::transaction(fn () => User::create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
            'timezone' => $request->validated('timezone'),
        ]));

        Auth::login($user);
        $request->session()->regenerate();

        return $user;
    }
}
