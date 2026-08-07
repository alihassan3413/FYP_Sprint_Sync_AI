<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ModuleBoundaryTest extends TestCase
{
    /**
     * A module may only reach into another module through its Contracts, Data,
     * Models, Actions, Policies and Exceptions. Services, Http and Support are private.
     *
     * @var array<int, string>
     */
    private const PUBLIC_SEGMENTS = ['Contracts', 'Data', 'Models', 'Actions', 'Policies', 'Exceptions'];

    /**
     * @var array<int, string>
     */
    private const REQUIRED_DIRECTORIES = ['Providers', 'Routes'];

    public function test_every_module_registers_a_service_provider(): void
    {
        foreach ($this->modules() as $module) {
            foreach (self::REQUIRED_DIRECTORIES as $directory) {
                $this->assertDirectoryExists(
                    $this->modulesPath()."/{$module}/{$directory}",
                    "Module [{$module}] is missing its {$directory} directory.",
                );
            }

            $this->assertFileExists(
                $this->modulesPath()."/{$module}/Providers/{$module}ServiceProvider.php",
                "Module [{$module}] is missing {$module}ServiceProvider.",
            );
        }
    }

    public function test_every_module_provider_is_registered_in_bootstrap(): void
    {
        $registered = require dirname(__DIR__, 2).'/bootstrap/providers.php';

        foreach ($this->modules() as $module) {
            $this->assertContains(
                "App\\Modules\\{$module}\\Providers\\{$module}ServiceProvider",
                $registered,
                "Module [{$module}] provider is not registered in bootstrap/providers.php.",
            );
        }
    }

    public function test_modules_only_use_the_public_surface_of_other_modules(): void
    {
        $violations = [];

        foreach ($this->modules() as $module) {
            foreach ($this->phpFilesIn($this->modulesPath().'/'.$module) as $file) {
                $contents = (string) file_get_contents($file->getPathname());

                preg_match_all('/^use\s+App\\\\Modules\\\\(\w+)\\\\(\w+)/m', $contents, $matches, PREG_SET_ORDER);

                foreach ($matches as [$statement, $targetModule, $segment]) {
                    if ($targetModule === $module || in_array($segment, self::PUBLIC_SEGMENTS, true)) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s imports %s (private segment "%s" of module %s)',
                        str_replace($this->modulesPath().'/', '', $file->getPathname()),
                        trim($statement),
                        $segment,
                        $targetModule,
                    );
                }
            }
        }

        $this->assertSame([], $violations, "Module boundary violations:\n".implode("\n", $violations));
    }

    public function test_no_module_registers_routes_outside_its_routes_directory(): void
    {
        foreach ($this->modules() as $module) {
            $this->assertFileDoesNotExist(
                $this->modulesPath()."/{$module}/routes.php",
                "Module [{$module}] still has a legacy routes.php file.",
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function modules(): array
    {
        return array_values(array_filter(
            scandir($this->modulesPath()) ?: [],
            fn (string $entry) => ! str_starts_with($entry, '.') && is_dir($this->modulesPath().'/'.$entry),
        ));
    }

    /**
     * @return array<int, SplFileInfo>
     */
    private function phpFilesIn(string $path): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function modulesPath(): string
    {
        return dirname(__DIR__, 2).'/app/Modules';
    }
}
