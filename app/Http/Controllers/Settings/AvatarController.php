<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AvatarUpdateRequest;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class AvatarController extends Controller
{
    public function store(AvatarUpdateRequest $request, RecordAuditLogAction $auditLogger): RedirectResponse
    {
        $user = $request->user();
        $previousPath = $user->avatar_path;

        $path = $request->file('avatar')->store('avatars', 'public');

        $user->forceFill(['avatar_path' => $path])->save();

        if ($previousPath !== null) {
            Storage::disk('public')->delete($previousPath);
        }

        $auditLogger->global(
            $user,
            AuditAction::ACCOUNT_AVATAR_UPDATED,
            "{$user->name} updated their profile picture.",
        );

        return to_route('profile.edit');
    }

    public function destroy(Request $request, RecordAuditLogAction $auditLogger): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_path === null) {
            return to_route('profile.edit');
        }

        Storage::disk('public')->delete($user->avatar_path);

        $user->forceFill(['avatar_path' => null])->save();

        $auditLogger->global(
            $user,
            AuditAction::ACCOUNT_AVATAR_REMOVED,
            "{$user->name} removed their profile picture.",
        );

        return to_route('profile.edit');
    }
}
