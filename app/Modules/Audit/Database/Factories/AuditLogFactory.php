<?php

declare(strict_types=1);

namespace App\Modules\Audit\Database\Factories;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
final class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'project_id' => null,
            'user_id' => User::factory(),
            'action' => AuditAction::PROJECT_CREATED->value,
            'subject_type' => Project::class,
            'subject_id' => fake()->numberBetween(1, 1000),
            'description' => fake()->sentence(),
            'metadata' => [],
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn () => ['workspace_id' => $workspace->id]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    public function byUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function action(AuditAction $action): static
    {
        return $this->state(fn () => ['action' => $action->value]);
    }
}
