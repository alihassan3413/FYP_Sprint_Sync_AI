<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Providers;

use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\Modules\Workspace\Policies\WorkspacePolicy;
use App\Modules\Workspace\Policies\WorkspaceRolePolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class WorkspaceServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Workspace';

    protected array $policies = [
        Workspace::class => WorkspacePolicy::class,
        WorkspaceRole::class => WorkspaceRolePolicy::class,
    ];

    protected function bootModule(): void
    {
        RateLimiter::for('invitation-send', fn (Request $request) => [
            Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()),
            Limit::perDay(100)->by($request->user()?->id ?: $request->ip()),
        ]);

        RateLimiter::for('invitation-accept', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
