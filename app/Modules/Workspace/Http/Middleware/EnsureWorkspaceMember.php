<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Middleware;

use App\Modules\Workspace\Exceptions\WorkspaceException;
use App\Modules\Workspace\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureWorkspaceMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');

        if (! $workspace instanceof Workspace) {
            throw WorkspaceException::notFound();
        }

        $user = $request->user();

        if ($user === null || ! $workspace->hasMember($user)) {
            throw WorkspaceException::notFound();
        }

        return $next($request);
    }
}
