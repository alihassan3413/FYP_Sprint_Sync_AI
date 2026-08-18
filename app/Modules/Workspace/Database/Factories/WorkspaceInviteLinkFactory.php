<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Database\Factories;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInviteLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkspaceInviteLink>
 */
final class WorkspaceInviteLinkFactory extends Factory
{
    protected $model = WorkspaceInviteLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'token' => Str::random(64),
            'uses' => 0,
            'expires_at' => now()->addDays(7),
            'revoked_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
