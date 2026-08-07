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

    private static function registrar(string $prefix = self::PARAMETER): RouteRegistrar
    {
        return Route::middleware(['auth', 'verified', EnsureWorkspaceMember::class])
            ->prefix($prefix)
            ->scopeBindings();
    }
}
