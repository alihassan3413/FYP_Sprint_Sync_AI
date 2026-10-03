<?php

declare(strict_types=1);

namespace App\Modules\People\Database\Factories;

use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
final class PersonFactory extends Factory
{
    protected $model = Person::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->name(),
            'email' => null,
            'title' => fake()->jobTitle(),
            'user_id' => null,
            'department_id' => null,
        ];
    }
}
