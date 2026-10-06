<?php

declare(strict_types=1);

namespace App\Modules\Desktop\Http\Controllers;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Desktop\Http\Requests\DesktopSignInRequest;
use App\Modules\Desktop\Models\DesktopDevice;
use App\Modules\Desktop\Support\DesktopTokens;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Sign in, who am I, sign out, for SprintSync Desktop. The password is only
 * checked (no web session is created) and the token is returned once.
 */
final class DesktopSessionController
{
    public function store(DesktopSignInRequest $request, DesktopTokens $tokens, RecordAuditLogAction $audit): JsonResponse
    {
        $credentials = $request->only(['email', 'password']);
        $web = Auth::guard('web');

        if (! $web->validate($credentials)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        /** @var User $user */
        $user = $web->getLastAttempted();

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => 'Verify your email address in SprintSync before signing in on desktop.']);
        }

        [$device, $token] = $tokens->issue($user, (string) $request->validated('device_name'), (string) $request->validated('platform'));

        $audit->global($user, AuditAction::ACCOUNT_DESKTOP_SIGNED_IN, "Signed in to SprintSync Desktop on {$device->name}.", ['device' => $device->public_id, 'platform' => $device->platform]);

        return response()->json([
            'token' => $token,
            'device' => $device->only(['public_id', 'name', 'platform']),
            ...$this->profile($user),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function destroy(Request $request, DesktopTokens $tokens, RecordAuditLogAction $audit): JsonResponse
    {
        /** @var DesktopDevice $device */
        $device = $request->attributes->get('desktop_device');
        $tokens->revoke($device);

        $audit->global($request->user(), AuditAction::ACCOUNT_DESKTOP_SIGNED_OUT, "Signed out of SprintSync Desktop on {$device->name}.", ['device' => $device->public_id]);

        return response()->json(['signed_out' => true]);
    }

    /**
     * The user and the workspaces they can track time in (client guests can't).
     *
     * @return array{user: array{name: string, email: string}, workspaces: list<array{slug: string, name: string, has_profile: bool}>}
     */
    private function profile(User $user): array
    {
        $linked = Person::query()->where('user_id', $user->id)->pluck('workspace_id')->flip();

        return [
            'user' => ['name' => $user->name, 'email' => $user->email],
            'workspaces' => $user->workspaces()
                ->wherePivot('role', '!=', UserRole::CLIENT->value)
                ->orderBy('name')
                ->get()
                ->map(fn (Workspace $workspace) => [
                    'slug' => $workspace->slug,
                    'name' => $workspace->name,
                    'has_profile' => $linked->has($workspace->id),
                ])
                ->values()
                ->all(),
        ];
    }
}
