<?php

declare(strict_types=1);

namespace App\Modules\People\Database\Factories;

use App\Modules\People\Models\Department;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
final class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->randomElement(['Engineering', 'Design', 'Customer Support', 'Operations', 'Finance', 'Sales', 'Marketing']),
        ];
    }
}
