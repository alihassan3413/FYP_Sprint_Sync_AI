<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

abstract class ModuleServiceProvider extends ServiceProvider
{
    protected string $module;

    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [];

    /**
     * @var array<string, string>
     */
    protected array $routeFiles = ['web' => 'web.php'];

    public function boot(): void
    {
        $this->bootPolicies();
        $this->bootMigrations();
        $this->bootRoutes();
        $this->bootModule();
    }

    protected function bootModule(): void {}

    public function moduleName(): string
    {
        return $this->module;
    }

    public function modulePath(string $relative = ''): string
    {
        return app_path('Modules/'.$this->module.($relative === '' ? '' : '/'.ltrim($relative, '/')));
    }

    private function bootPolicies(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    private function bootMigrations(): void
    {
        $path = $this->modulePath('Database/Migrations');

        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    private function bootRoutes(): void
    {
        foreach ($this->routeFiles as $middlewareGroup => $file) {
            $path = $this->modulePath('Routes/'.$file);

            if (is_file($path)) {
                Route::middleware($middlewareGroup)->group($path);
            }
        }
    }
}
