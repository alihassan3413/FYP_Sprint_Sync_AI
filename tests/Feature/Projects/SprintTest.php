<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SprintTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    private function sprintRoute(string $name, ?Sprint $sprint = null): string
    {
        $params = ['workspace' => $this->workspace->slug, 'project' => $this->project->id];

        if ($sprint !== null) {
            $params['sprint'] = $sprint->id;
        }

        return route($name, $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sprint 1',
            'goal' => 'Ship the board.',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(13)->toDateString(),
        ], $overrides);
    }

    public function test_an_admin_can_create_a_sprint(): void
    {
        $this->actingAs($this->owner)
            ->post($this->sprintRoute('workspace.projects.sprints.store'), $this->payload())
            ->assertRedirect();

        $sprint = Sprint::query()->firstOrFail();

        $this->assertSame('Sprint 1', $sprint->name);
        $this->assertSame($this->project->id, $sprint->project_id);
        $this->assertSame($this->workspace->id, $sprint->workspace_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sprint.created']);
    }

    public function test_a_project_manager_can_create_a_sprint(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($manager)
            ->post($this->sprintRoute('workspace.projects.sprints.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame(1, Sprint::query()->count());
    }

    public function test_a_plain_project_member_cannot_create_a_sprint(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($member)
            ->post($this->sprintRoute('workspace.projects.sprints.store'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Sprint::query()->count());
    }

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post($this->sprintRoute('workspace.projects.sprints.store'), $this->payload([
                'starts_on' => now()->addDays(5)->toDateString(),
                'ends_on' => now()->toDateString(),
            ]))
            ->assertSessionHasErrors('ends_on');

        $this->assertSame(0, Sprint::query()->count());
    }

    public function test_overlapping_sprints_in_the_same_project_are_rejected(): void
    {
        Sprint::factory()->forProject($this->project)->create([
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(13)->toDateString(),
        ]);

        $this->actingAs($this->owner)
            ->post($this->sprintRoute('workspace.projects.sprints.store'), $this->payload([
                'starts_on' => now()->addDays(7)->toDateString(),
                'ends_on' => now()->addDays(20)->toDateString(),
            ]))
            ->assertSessionHasErrors('starts_on');

        $this->assertSame(1, Sprint::query()->count());
    }

    public function test_an_overlapping_sprint_in_another_project_is_allowed(): void
    {
        $other = Project::factory()->forWorkspace($this->workspace)->create();

        Sprint::factory()->forProject($other)->create([
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(13)->toDateString(),
        ]);

        $this->actingAs($this->owner)
            ->post($this->sprintRoute('workspace.projects.sprints.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Sprint::query()->count());
    }

    public function test_a_sprint_can_be_updated_without_tripping_its_own_overlap_check(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->current()->create();

        $this->actingAs($this->owner)
            ->put($this->sprintRoute('workspace.projects.sprints.update', $sprint), $this->payload([
                'name' => 'Renamed sprint',
                'starts_on' => $sprint->starts_on->toDateString(),
                'ends_on' => $sprint->ends_on->toDateString(),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed sprint', $sprint->refresh()->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sprint.updated']);
    }

    public function test_deleting_a_sprint_keeps_its_tasks_and_unassigns_them(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->current()->create();
        $column = BoardColumn::query()->where('project_id', $this->project->id)->firstOrFail();

        $task = Task::factory()->forProject($this->project)->forColumn($column)->create(['sprint_id' => $sprint->id]);

        $this->actingAs($this->owner)
            ->delete($this->sprintRoute('workspace.projects.sprints.destroy', $sprint))
            ->assertRedirect();

        $this->assertSame(0, Sprint::query()->count());
        $this->assertNotNull($task->fresh());
        $this->assertNull($task->fresh()->sprint_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sprint.deleted']);
    }

    public function test_a_sprint_from_another_project_cannot_be_updated_through_this_project(): void
    {
        $other = Project::factory()->forWorkspace($this->workspace)->create();
        $foreign = Sprint::factory()->forProject($other)->current()->create();

        $this->actingAs($this->owner)
            ->put($this->sprintRoute('workspace.projects.sprints.update', $foreign), $this->payload())
            ->assertNotFound();
    }

    public function test_a_user_from_another_workspace_cannot_create_a_sprint(): void
    {
        $outsider = User::factory()->create();
        Workspace::factory()->ownedBy($outsider)->create();

        $this->actingAs($outsider)
            ->post($this->sprintRoute('workspace.projects.sprints.store'), $this->payload())
            ->assertNotFound();

        $this->assertSame(0, Sprint::query()->count());
    }

    public function test_the_current_sprint_is_resolved_by_date(): void
    {
        Sprint::factory()->forProject($this->project)->past()->create(['name' => 'Old']);
        $current = Sprint::factory()->forProject($this->project)->current()->create(['name' => 'Now']);
        Sprint::factory()->forProject($this->project)->upcoming()->create(['name' => 'Next']);

        $this->assertSame($current->id, $this->project->currentSprint()?->id);
        $this->assertTrue($current->isCurrent());
        $this->assertFalse($current->isUpcoming());
    }

    public function test_a_project_with_no_current_sprint_resolves_null(): void
    {
        Sprint::factory()->forProject($this->project)->past()->create();

        $this->assertNull($this->project->currentSprint());
    }

    public function test_the_project_page_exposes_sprints_and_the_manage_capability(): void
    {
        Sprint::factory()->forProject($this->project)->current()->create(['name' => 'Sprint 7']);

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', ['workspace' => $this->workspace->slug, 'project' => $this->project->id]))
            ->assertInertia(fn ($page) => $page
                ->where('canManageSprints', true)
                ->has('sprints', 1)
                ->where('sprints.0.name', 'Sprint 7')
                ->where('sprints.0.is_current', true));
    }

    public function test_a_task_can_be_assigned_to_a_sprint_in_its_own_project(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->current()->create();
        $column = BoardColumn::query()->where('project_id', $this->project->id)->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.tasks.store', [
                'workspace' => $this->workspace->slug,
                'project' => $this->project->id,
            ]), [
                'title' => 'Sprint task',
                'board_column_id' => $column->id,
                'sprint_id' => $sprint->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($sprint->id, Task::query()->firstOrFail()->sprint_id);
    }

    public function test_a_sprint_from_another_project_cannot_be_attached_to_a_task(): void
    {
        $other = Project::factory()->forWorkspace($this->workspace)->create();
        $foreignSprint = Sprint::factory()->forProject($other)->current()->create();
        $column = BoardColumn::query()->where('project_id', $this->project->id)->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.tasks.store', [
                'workspace' => $this->workspace->slug,
                'project' => $this->project->id,
            ]), [
                'title' => 'Sneaky task',
                'board_column_id' => $column->id,
                'sprint_id' => $foreignSprint->id,
            ])
            ->assertSessionHasErrors('sprint_id');

        $this->assertSame(0, Task::query()->count());
    }
}
