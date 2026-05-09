<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'make:module {name : The module name, for example Projects}';

    protected $description = 'Create a clean module folder structure';

    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));

        $modulePath = app_path("Modules/{$name}");

        if (File::exists($modulePath)) {
            $this->error("Module [{$name}] already exists.");

            return self::FAILURE;
        }

        $directories = [
            "{$modulePath}/Actions",
            "{$modulePath}/Data",
            "{$modulePath}/Http/Controllers",
            "{$modulePath}/Http/Requests",
            "{$modulePath}/Models",
            "{$modulePath}/Services",
        ];

        foreach ($directories as $directory) {
            File::makeDirectory($directory, 0755, true);
        }

        $this->createRoutesFile($name, $modulePath);

        $this->info("Module [{$name}] created successfully.");
        $this->line("Created at: app/Modules/{$name}");

        return self::SUCCESS;
    }

    private function createRoutesFile(string $name, string $modulePath): void
    {
        $routePrefix = Str::kebab($name);
        $routeName = Str::kebab($name);

        $content = <<<PHP
<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('{$routePrefix}')
    ->name('{$routeName}.')
    ->group(function () {
        // Example:
        // Route::get('/', [{$name}Controller::class, 'index'])->name('index');
    });

PHP;

        File::put("{$modulePath}/routes.php", $content);
    }
}
