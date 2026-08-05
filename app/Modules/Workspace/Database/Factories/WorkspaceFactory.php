<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Database\Factories;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
final class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'settings' => [],
            'is_active' => true,
            'owner_id' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn () => ['owner_id' => $owner->id])
            ->afterCreating(fn (Workspace $workspace) => $workspace->users()->syncWithoutDetaching([
                $owner->id => ['role' => UserRole::OWNER->value],
            ]));
    }

    public function withMember(User $user, UserRole $role = UserRole::MEMBER): static
    {
        return $this->afterCreating(fn (Workspace $workspace) => $workspace->users()->syncWithoutDetaching([
            $user->id => ['role' => $role->value],
        ]));
    }
}
