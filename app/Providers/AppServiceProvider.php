<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Routing\LandingRoute;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        $this->redirectAuthenticatedGuests();
    }

    /**
     * The `dashboard` route is workspace scoped, so the framework default of
     * `route('dashboard')` cannot be generated without a workspace slug.
     */
    private function redirectAuthenticatedGuests(): void
    {
        RedirectIfAuthenticated::redirectUsing(function (Request $request): string {
            $user = $request->user();

            return $user === null ? route('home') : LandingRoute::for($user);
        });
    }
}
