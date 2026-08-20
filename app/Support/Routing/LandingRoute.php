<?php

declare(strict_types=1);

namespace App\Support\Routing;

use App\Models\User;

/**
 * Where a user belongs immediately after signing in.
 *
 * The dashboard is workspace-scoped, so it cannot be the answer for someone
 * who has no workspace — a platform administrator, or anyone whose only
 * workspace was deleted. Resolving that here keeps sign-in and the
 * already-authenticated redirect agreeing with each other.
 */
final class LandingRoute
{
    public static function for(User $user): string
    {
        $workspace = $user->resolveActiveWorkspace();

        if ($workspace !== null) {
            return route('dashboard', ['workspace' => $workspace->slug], false);
        }

        if ($user->isSuperAdmin()) {
            return route('admin.dashboard', absolute: false);
        }

        return route('home', absolute: false);
    }
}
