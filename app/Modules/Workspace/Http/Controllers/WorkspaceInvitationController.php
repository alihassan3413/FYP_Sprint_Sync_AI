<?php

namespace App\Modules\Workspace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Workspace\Actions\AcceptWorkspaceInvitationAction;
use App\Modules\Workspace\Actions\CreateWorkspaceInvitationAction;
use App\Modules\Workspace\Http\Requests\AcceptWorkspaceInvitationRequest;
use App\Modules\Workspace\Http\Requests\StoreWorkspaceInvitationRequest;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Response;

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

    public function accept(
        string $token,
        AcceptWorkspaceInvitationRequest $request,
        AcceptWorkspaceInvitationAction $acceptWorkspaceInvitationAction
    ): RedirectResponse {
        $invitation = WorkspaceInvitation::query()
            ->where('token', $token)
            ->firstOrFail();

        $user = $request->user();

        /**
         * Guest invited user:
         * create account first using invitation email.
         */
        if (! $user) {
            if (User::query()->where('email', $invitation->email)->exists()) {
                session(['pending_invitation_token' => $token]);

                return redirect()
                    ->route('login')
                    ->with('error', 'An account already exists for this email. Please login to accept the invitation.');
            }

            $user = User::query()->create([
                'name' => $request->string('name')->toString(),
                'email' => $invitation->email,
                'password' => Hash::make($request->string('password')->toString()),
            ]);

            Auth::login($user);
        }

        $acceptWorkspaceInvitationAction->handle($token, $user);

        return redirect()
            ->route('dashboard', ['workspace' => $invitation->workspace->slug])
            ->with('success', 'Invitation accepted successfully.');
    }

    public function showAccept(string $token): Response|RedirectResponse
    {
        $invitation = WorkspaceInvitation::query()
            ->with('workspace')
            ->where('token', $token)
            ->firstOrFail();

        if ($invitation->accepted_at) {
            return redirect()
                ->route('login')
                ->with('error', 'This invitation has already been accepted.');
        }

        if ($invitation->expires_at->isPast()) {
            return redirect()
                ->route('login')
                ->with('error', 'This invitation has expired.');
        }

        $authUser = Auth::user();

        if ($authUser) {
            if ($authUser->email !== $invitation->email) {
                return redirect()
                    ->route('dashboard', ['workspace' => $invitation->workspace->slug])
                    ->with('error', "This invitation was sent to {$invitation->email}. Please login with that email to accept it.");
            }

            app(AcceptWorkspaceInvitationAction::class)->handle($token, $authUser);

            return redirect()
                ->route('dashboard', ['workspace' => $invitation->workspace->slug])
                ->with('success', 'Invitation accepted successfully.');
        }

        $existingUser = User::query()
            ->where('email', $invitation->email)
            ->exists();

        if ($existingUser) {
            session(['pending_invitation_token' => $token]);

            return redirect()
                ->route('login')
                ->with('status', 'Please login to accept your workspace invitation.');
        }

        return inertia('workspace/invitations/Accept', [
            'token' => $token,
            'invitation' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'workspace' => [
                    'name' => $invitation->workspace->name,
                ],
            ],
        ]);
    }
}
