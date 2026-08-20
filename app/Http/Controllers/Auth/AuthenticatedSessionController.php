<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Modules\Workspace\Actions\AcceptWorkspaceInvitationAction;
use App\Modules\Workspace\Exceptions\WorkspaceException;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\Support\Routing\LandingRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

final class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request, AcceptWorkspaceInvitationAction $action): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = $request->user();
        $token = $request->session()->pull('pending_invitation_token');

        if ($token !== null) {
            return $this->acceptPendingInvitation($action, $user, (string) $token);
        }

        return redirect()->intended(LandingRoute::for($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function acceptPendingInvitation(
        AcceptWorkspaceInvitationAction $action,
        User $user,
        string $token,
    ): RedirectResponse {
        $invitation = WorkspaceInvitation::query()->with('workspace')->where('token', $token)->first();

        if ($invitation === null) {
            return $this->toOwnDashboard($user, 'That invitation is no longer valid.');
        }

        try {
            $action->handle($invitation, $user);
        } catch (WorkspaceException $e) {
            return $this->toOwnDashboard($user, $e->getMessage());
        }

        return to_route('dashboard', ['workspace' => $invitation->workspace->slug])
            ->with('success', "You have joined {$invitation->workspace->name}.");
    }

    private function toOwnDashboard(User $user, string $message): RedirectResponse
    {
        return to_route('dashboard', ['workspace' => $user->activeWorkspaceOrFail()->slug])
            ->with('error', $message);
    }
}
