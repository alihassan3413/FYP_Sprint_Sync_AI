<?php

use Illuminate\Support\Facades\File;

if (! function_exists('load_module_routes')) {
    function load_module_routes(): void
    {
        $modulesPath = app_path('Modules');

        if (! File::exists($modulesPath)) {
            return;
        }

        foreach (File::directories($modulesPath) as $moduleDirectory) {
            $routesFile = $moduleDirectory . DIRECTORY_SEPARATOR . 'routes.php';

            if (File::exists($routesFile)) {
                require $routesFile;
            }
        }
    }
}
