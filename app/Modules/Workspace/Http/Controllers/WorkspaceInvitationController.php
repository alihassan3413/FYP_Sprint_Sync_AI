<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Controllers;

use App\Models\User;
use App\Modules\Workspace\Actions\AcceptWorkspaceInvitationAction;
use App\Modules\Workspace\Actions\CreateWorkspaceInvitationAction;
use App\Modules\Workspace\Http\Requests\AcceptWorkspaceInvitationRequest;
use App\Modules\Workspace\Http\Requests\StoreWorkspaceInvitationRequest;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceInvitationController
{
    public function create(Workspace $workspace): Response
    {
        return Inertia::render('workspace/invitations/Create', [
            'workspace' => ['name' => $workspace->name, 'slug' => $workspace->slug],
        ]);
    }

    public function store(
        StoreWorkspaceInvitationRequest $request,
        Workspace $workspace,
        CreateWorkspaceInvitationAction $action,
    ): RedirectResponse {
        $invitation = $action->handle($workspace, $request->user(), $request->toDTO());

        return back()->with('success', "Invitation sent to {$invitation->email}.");
    }

    public function resend(
        Request $request,
        Workspace $workspace,
        WorkspaceInvitation $invitation,
        CreateWorkspaceInvitationAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('invite', $workspace), 403);

        $action->resend($workspace, $request->user(), $invitation);

        return back()->with('success', "Invitation resent to {$invitation->email}.");
    }

    public function destroy(Request $request, Workspace $workspace, WorkspaceInvitation $invitation): RedirectResponse
    {
        abort_unless($request->user()->can('invite', $workspace), 403);

        $invitation->delete();

        return back()->with('success', "Invitation to {$invitation->email} revoked.");
    }

    public function showAccept(string $token): Response|RedirectResponse
    {
        $invitation = $this->findInvitation($token);

        if ($invitation->isAccepted()) {
            return to_route('login')->with('error', 'This invitation has already been accepted.');
        }

        if ($invitation->isExpired()) {
            return to_route('login')->with('error', 'This invitation has expired.');
        }

        $user = Auth::user();

        if ($user !== null && $user->email !== $invitation->email) {
            return to_route('login')->with(
                'error',
                "This invitation was sent to {$invitation->email}. Sign in with that address to accept it.",
            );
        }

        if ($user === null && User::query()->where('email', $invitation->email)->exists()) {
            session(['pending_invitation_token' => $token]);

            return to_route('login')->with('status', 'Please sign in to accept your workspace invitation.');
        }

        return Inertia::render('workspace/invitations/Accept', [
            'token' => $token,
            'requiresRegistration' => $user === null,
            'invitation' => [
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'workspace' => ['name' => $invitation->workspace->name],
            ],
        ]);
    }

    public function accept(
        string $token,
        AcceptWorkspaceInvitationRequest $request,
        AcceptWorkspaceInvitationAction $action,
    ): RedirectResponse {
        $invitation = $this->findInvitation($token);
        $user = $request->user() ?? $this->registerInvitee($request, $invitation);

        $action->handle($invitation, $user);

        session()->forget('pending_invitation_token');

        return to_route('dashboard', ['workspace' => $invitation->workspace->slug])
            ->with('success', "You have joined {$invitation->workspace->name}.");
    }

    private function findInvitation(string $token): WorkspaceInvitation
    {
        return WorkspaceInvitation::query()
            ->with('workspace')
            ->where('token', $token)
            ->firstOrFail();
    }

    private function registerInvitee(AcceptWorkspaceInvitationRequest $request, WorkspaceInvitation $invitation): User
    {
        $user = DB::transaction(fn () => User::create([
            'name' => $request->string('name')->toString(),
            'email' => $invitation->email,
            'password' => Hash::make($request->string('password')->toString()),
        ]));

        $user->forceFill(['email_verified_at' => now()])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return $user;
    }
}
