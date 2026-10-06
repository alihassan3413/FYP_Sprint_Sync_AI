<?php

declare(strict_types=1);

namespace App\Modules\Desktop\Providers;

use App\Modules\Desktop\Support\DesktopTokens;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * SprintSync Desktop sign-in. The "desktop" guard reads a Bearer token on the
 * desktop API routes only; web sessions are untouched. The device is kept on
 * the request so sign-out revokes exactly this device.
 */
final class DesktopServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Desktop';

    protected array $routeFiles = ['web' => 'web.php', 'api' => 'api.php'];

    protected function bootModule(): void
    {
        Auth::viaRequest('desktop-token', function (Request $request) {
            $device = app(DesktopTokens::class)->resolve($request->bearerToken());

            if ($device === null) {
                return null;
            }

            $request->attributes->set('desktop_device', $device);

            return $device->user;
        });

        RateLimiter::for('desktop-login', fn (Request $request) => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
    }
}
