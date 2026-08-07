<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
        {name : The module name, for example Projects}
        {--tenant : Scope the module routes to a workspace}';

    protected $description = 'Scaffold a module with its service provider, routes and test namespace';

    /**
     * @var array<int, string>
     */
    private const DIRECTORIES = [
        'Actions',
        'Contracts',
        'Data',
        'Database/Factories',
        'Database/Migrations',
        'Exceptions',
        'Http/Controllers',
        'Http/Requests',
        'Models',
        'Policies',
        'Providers',
        'Routes',
        'Services',
    ];

    public function handle(): int
    {
        $name = Str::studly((string) $this->argument('name'));
        $path = app_path("Modules/{$name}");

        if (File::exists($path)) {
            $this->components->error("Module [{$name}] already exists.");

            return self::FAILURE;
        }

        foreach (self::DIRECTORIES as $directory) {
            File::ensureDirectoryExists("{$path}/{$directory}", 0755);
        }

        File::ensureDirectoryExists(base_path("tests/Feature/{$name}"), 0755);

        File::put("{$path}/Providers/{$name}ServiceProvider.php", $this->providerStub($name));
        File::put("{$path}/Routes/web.php", $this->routesStub($name));

        $this->components->info("Module [{$name}] created at app/Modules/{$name}.");
        $this->components->warn("Register App\\Modules\\{$name}\\Providers\\{$name}ServiceProvider in bootstrap/providers.php.");

        return self::SUCCESS;
    }

    private function providerStub(string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Modules\\{$name}\\Providers;

        use App\\Support\\Modules\\ModuleServiceProvider;

        final class {$name}ServiceProvider extends ModuleServiceProvider
        {
            protected string \$module = '{$name}';

            protected array \$policies = [
                //
            ];
        }

        PHP;
    }

    private function routesStub(string $name): string
    {
        $segment = Str::kebab(Str::plural($name));

        if ($this->option('tenant')) {
            return <<<PHP
            <?php

            declare(strict_types=1);

            use App\\Support\\Routing\\TenantRoute;

            TenantRoute::prefixed('{$segment}', 'workspace.{$segment}.', function () {
                //
            });

            PHP;
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        use Illuminate\\Support\\Facades\\Route;

        Route::middleware(['auth', 'verified'])
            ->prefix('{$segment}')
            ->name('{$segment}.')
            ->group(function () {
                //
            });

        PHP;
    }
}
