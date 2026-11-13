<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Admin\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BlockDuringImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Impersonation::active($request)) {
            return $next($request);
        }

        $message = 'Account settings can’t be changed while you’re viewing as someone else.';

        if ($request->header('X-Inertia') || ! $request->expectsJson()) {
            return back()->with('error', $message);
        }

        return response()->json(['message' => $message], 403);
    }
}
