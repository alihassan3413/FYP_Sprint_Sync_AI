<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, RecordAuditLogAction $auditLogger): RedirectResponse
    {
        $user = $request->user();

        $user->fill($request->validated());

        $changedFields = array_values(array_intersect(
            array_keys($request->validated()),
            array_keys($user->getDirty()),
        ));

        if ($changedFields === []) {
            return to_route('profile.edit');
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $auditLogger->global(
            $user,
            AuditAction::ACCOUNT_PROFILE_UPDATED,
            "{$user->name} updated their profile.",
            ['changed_fields' => $changedFields],
        );

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(Request $request, RecordAuditLogAction $auditLogger): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $description = "{$user->name} ({$user->email}) deleted their account.";

        Auth::logout();

        $user->delete();

        $auditLogger->global($user, AuditAction::ACCOUNT_DELETED, $description);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
