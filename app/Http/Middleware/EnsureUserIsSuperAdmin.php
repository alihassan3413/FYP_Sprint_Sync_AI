<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the platform admin panel.
 *
 * A 404 rather than a 403: the panel's existence is not something a normal
 * account needs to learn about from probing routes.
 */
final class EnsureUserIsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isSuperAdmin() === true, 404);

        return $next($request);
    }
}
