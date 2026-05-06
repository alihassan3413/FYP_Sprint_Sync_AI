<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Modules\Workspace\Actions\AcceptWorkspaceInvitationAction;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(
        LoginRequest $request,
        AcceptWorkspaceInvitationAction $acceptWorkspaceInvitationAction,
    ): RedirectResponse {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        $pendingInvitationToken = $request->session()->pull('pending_invitation_token');

        if ($pendingInvitationToken) {
            $invitation = WorkspaceInvitation::query()
                ->where('token', $pendingInvitationToken)
                ->first();

            if (! $invitation) {
                return redirect()
                    ->route('dashboard', ['workspace' => $user->activeWorkspaceOrFail()->slug])
                    ->with('error', 'Invitation not found.');
            }

            if ($invitation->accepted_at) {
                return redirect()
                    ->route('dashboard', ['workspace' => $invitation->workspace->slug])
                    ->with('error', 'This invitation has already been accepted.');
            }

            if ($invitation->expires_at->isPast()) {
                return redirect()
                    ->route('dashboard', ['workspace' => $invitation->workspace->slug])
                    ->with('error', 'This invitation has expired.');
            }

            if ($user->email !== $invitation->email) {
                return redirect()
                    ->route('dashboard', ['workspace' => $invitation->workspace->slug])
                    ->with('error', "This invitation was sent to {$invitation->email}. Please login with that email to accept it.");
            }

            $acceptWorkspaceInvitationAction->handle($pendingInvitationToken, $user);

            return redirect()
                ->route('dashboard', ['workspace' => $invitation->workspace->slug])
                ->with('success', 'Invitation accepted successfully.');
        }

        $workspace = $user->activeWorkspaceOrFail();

        return redirect()->intended(
            route('dashboard', ['workspace' => $workspace->slug], false)
        );
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
