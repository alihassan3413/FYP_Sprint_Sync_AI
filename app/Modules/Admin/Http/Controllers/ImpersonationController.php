<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Models\User;
use App\Modules\Admin\Support\Impersonation;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Support\Routing\LandingRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ImpersonationController
{
    public function start(Request $request, User $user, RecordAuditLogAction $auditLogger): RedirectResponse
    {
        $admin = $request->user();

        if ($user->is($admin)) {
            return back()->with('error', 'You are already signed in as yourself.');
        }

        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Other super admins can’t be viewed as.');
        }

        $auditLogger->global(
            $admin,
            AuditAction::ACCOUNT_IMPERSONATION_STARTED,
            "{$admin->name} started viewing SprintSync as {$user->name} ({$user->email}).",
            ['impersonated_user_id' => $user->id, 'impersonated_email' => $user->email],
        );

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(Impersonation::SESSION_KEY, $admin->id);

        return redirect(LandingRoute::for($user))->with('info', "You’re now viewing SprintSync as {$user->name}.");
    }

    public function stop(Request $request, RecordAuditLogAction $auditLogger): RedirectResponse
    {
        $impersonatorId = Impersonation::impersonatorId($request);
        $viewed = $request->user();
        $admin = $impersonatorId === null ? null : User::query()->find($impersonatorId);

        $request->session()->forget(Impersonation::SESSION_KEY);

        if ($admin === null || ! $admin->isSuperAdmin()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login');
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        $auditLogger->global(
            $admin,
            AuditAction::ACCOUNT_IMPERSONATION_ENDED,
            "{$admin->name} stopped viewing SprintSync as {$viewed?->name}.",
            ['impersonated_user_id' => $viewed?->id],
        );

        return to_route('admin.dashboard')->with('success', 'You’re back in your own account.');
    }
}
