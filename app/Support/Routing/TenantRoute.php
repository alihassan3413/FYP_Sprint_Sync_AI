<?php

declare(strict_types=1);

namespace App\Support\Routing;

use App\Modules\Workspace\Http\Middleware\EnsureWorkspaceMember;
use Closure;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Route;

final class TenantRoute
{
    public const PARAMETER = '{workspace:slug}';

    public static function group(Closure $routes): void
    {
        self::registrar()->group($routes);
    }

    public static function prefixed(string $prefix, string $namePrefix, Closure $routes): void
    {
        self::registrar(self::PARAMETER.'/'.trim($prefix, '/'))
            ->name($namePrefix)
            ->group($routes);
    }

    /**
     * Workspace routes for SprintSync Desktop: /api/desktop/{workspace}/{prefix},
     * authenticated by the desktop device token instead of a session, with
     * the same membership check and scoped bindings as the web routes.
     */
    public static function desktop(string $prefix, string $namePrefix, Closure $routes): void
    {
        Route::middleware(['auth:desktop', EnsureWorkspaceMember::class])
            ->prefix('api/desktop/'.self::PARAMETER.'/'.trim($prefix, '/'))
            ->name($namePrefix)
            ->scopeBindings()
            ->group($routes);
    }

    private static function registrar(string $prefix = self::PARAMETER): RouteRegistrar
    {
        return Route::middleware(['auth', 'verified', EnsureWorkspaceMember::class])
            ->prefix($prefix)
            ->scopeBindings();
    }
}
