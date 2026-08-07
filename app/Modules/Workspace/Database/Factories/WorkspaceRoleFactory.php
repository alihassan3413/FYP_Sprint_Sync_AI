<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Database\Factories;

use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkspaceRole>
 */
final class WorkspaceRoleFactory extends Factory
{
    protected $model = WorkspaceRole::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'permissions' => ['projects.view' => true],
            'workspace_id' => Workspace::factory(),
        ];
    }
}
