<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Modules\Projects\Actions\CompleteSprintAction;
use App\Modules\Projects\Actions\StartSprintAction;
use App\Modules\Projects\Data\SprintCarryOver;
use App\Modules\Projects\Data\SprintStatus;
use App\Modules\Projects\Exceptions\SprintException;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SprintLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    private BoardColumn $todoColumn;

    private BoardColumn $doneColumn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->todoColumn = $this->project->boardColumns()->where('position', 0)->firstOrFail();
        $this->doneColumn = $this->project->boardColumns()->where('is_done', true)->firstOrFail();
    }

    private function task(?Sprint $sprint = null, bool $done = false): Task
    {
        return Task::factory()->create([
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'sprint_id' => $sprint?->id,
            'board_column_id' => $done ? $this->doneColumn->id : $this->todoColumn->id,
            'completed_at' => $done ? now() : null,
        ]);
    }

    private function sprintRoute(string $name, Sprint $sprint): string
    {
        return route($name, [
            'workspace' => $this->workspace->slug,
            'project' => $this->project->id,
            'sprint' => $sprint->id,
        ]);
    }

    public function test_a_new_sprint_starts_out_planned(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.projects.sprints.store', [$this->workspace, $this->project]), [
                'name' => 'Sprint 1',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(13)->toDateString(),
            ])
            ->assertRedirect();

        $sprint = Sprint::query()->where('name', 'Sprint 1')->firstOrFail();

        $this->assertSame(SprintStatus::Planned, $sprint->status);
        $this->assertNull($sprint->started_at);
    }

    public function test_starting_a_sprint_commits_its_current_scope(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->planned()->create();
        $this->task($sprint);
        $this->task($sprint);

        $this->actingAs($this->owner)
            ->post($this->sprintRoute('workspace.projects.sprints.start', $sprint))
            ->assertRedirect()
            ->assertSessionHas('success');

        $sprint->refresh();

        $this->assertSame(SprintStatus::Active, $sprint->status);
        $this->assertNotNull($sprint->started_at);
        $this->assertSame(2, $sprint->committed_task_count);
    }

    public function test_only_one_sprint_can_run_per_project(): void
    {
        Sprint::factory()->forProject($this->project)->running()->create(['name' => 'Sprint 1']);
        $next = Sprint::factory()->forProject($this->project)->planned()->upcoming()->create(['name' => 'Sprint 2']);

        $this->expectException(SprintException::class);

        app(StartSprintAction::class)->handle($next, $this->owner);
    }

    public function test_a_sprint_cannot_be_started_twice(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create();

        $this->expectException(SprintException::class);

        app(StartSprintAction::class)->handle($sprint, $this->owner);
    }

    public function test_completing_a_sprint_freezes_its_numbers_and_sends_unfinished_work_to_the_backlog(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create(['committed_task_count' => 3]);

        $done = $this->task($sprint, done: true);
        $open = $this->task($sprint);
        $alsoOpen = $this->task($sprint);

        $this->actingAs($this->owner)
            ->post($this->sprintRoute('workspace.projects.sprints.complete', $sprint), [
                'carry_over' => SprintCarryOver::Backlog->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $sprint->refresh();

        $this->assertSame(SprintStatus::Completed, $sprint->status);
        $this->assertNotNull($sprint->completed_at);
        $this->assertSame(1, $sprint->completed_task_count);
        $this->assertSame(2, $sprint->carried_over_task_count);

        $this->assertSame($sprint->id, $done->fresh()->sprint_id);
        $this->assertNull($open->fresh()->sprint_id);
        $this->assertNull($alsoOpen->fresh()->sprint_id);
    }

    public function test_unfinished_work_can_be_carried_into_the_next_planned_sprint(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create();
        $next = Sprint::factory()->forProject($this->project)->planned()->upcoming()->create();

        $open = $this->task($sprint);
        $done = $this->task($sprint, done: true);

        app(CompleteSprintAction::class)->handle($sprint, $this->owner, SprintCarryOver::NextSprint);

        $this->assertSame($next->id, $open->fresh()->sprint_id);
        $this->assertSame($sprint->id, $done->fresh()->sprint_id);
    }

    public function test_carrying_over_falls_back_to_the_backlog_when_there_is_no_planned_sprint(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create();
        $open = $this->task($sprint);

        app(CompleteSprintAction::class)->handle($sprint, $this->owner, SprintCarryOver::NextSprint);

        $this->assertNull($open->fresh()->sprint_id);
    }

    public function test_a_sprint_from_another_project_cannot_receive_the_carry_over(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create();
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();
        $foreign = Sprint::factory()->forProject($otherProject)->planned()->create();

        $this->expectException(SprintException::class);

        app(CompleteSprintAction::class)->handle($sprint, $this->owner, SprintCarryOver::NextSprint, $foreign);
    }

    public function test_a_completed_sprint_is_frozen(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->completed(4)->past()->create();

        $this->actingAs($this->owner)
            ->put($this->sprintRoute('workspace.projects.sprints.update', $sprint), [
                'name' => 'Renamed',
                'starts_on' => $sprint->starts_on->toDateString(),
                'ends_on' => $sprint->ends_on->toDateString(),
            ])
            ->assertStatus(422);

        $this->actingAs($this->owner)
            ->delete($this->sprintRoute('workspace.projects.sprints.destroy', $sprint))
            ->assertStatus(422);

        $this->assertDatabaseHas('sprints', ['id' => $sprint->id, 'name' => $sprint->name]);
    }

    public function test_a_plain_project_member_cannot_run_the_sprint(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $sprint = Sprint::factory()->forProject($this->project)->planned()->create();

        $this->actingAs($member)
            ->post($this->sprintRoute('workspace.projects.sprints.start', $sprint))
            ->assertForbidden();
    }

    public function test_moving_a_task_in_and_out_of_a_done_column_tracks_completion(): void
    {
        $task = $this->task();

        $this->actingAs($this->owner)
            ->patch(route('workspace.projects.tasks.update-status', [
                'workspace' => $this->workspace->slug,
                'project' => $this->project->id,
                'task' => $task->id,
            ]), ['board_column_id' => $this->doneColumn->id])
            ->assertRedirect();

        $this->assertNotNull($task->fresh()->completed_at);

        $this->actingAs($this->owner)
            ->patch(route('workspace.projects.tasks.update-status', [
                'workspace' => $this->workspace->slug,
                'project' => $this->project->id,
                'task' => $task->id,
            ]), ['board_column_id' => $this->todoColumn->id])
            ->assertRedirect();

        $this->assertNull($task->fresh()->completed_at);
    }
}
