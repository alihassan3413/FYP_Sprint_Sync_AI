<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $projectMember;

    private User $unrelatedMember;

    private Project $project;

    private BoardColumn $todoColumn;

    private BoardColumn $inProgressColumn;

    private BoardColumn $doneColumn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->projectMember = User::factory()->create();
        $this->workspace->users()->attach($this->projectMember->id, ['role' => UserRole::MEMBER->value]);

        $this->unrelatedMember = User::factory()->create();
        $this->workspace->users()->attach($this->unrelatedMember->id, ['role' => UserRole::MEMBER->value]);

        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Apollo']);
        $this->project->members()->attach($this->projectMember->id, ['role' => ProjectRole::MEMBER->value]);

        $this->todoColumn = BoardColumn::query()->where('project_id', $this->project->id)->where('name', 'To Do')->firstOrFail();
        $this->inProgressColumn = BoardColumn::query()->where('project_id', $this->project->id)->where('name', 'In Progress')->firstOrFail();
        $this->doneColumn = BoardColumn::query()->where('project_id', $this->project->id)->where('name', 'Done')->firstOrFail();
    }

    private function analyticsRoute(array $params = []): string
    {
        return route('workspace.analytics.index', array_merge(['workspace' => $this->workspace], $params));
    }

    public function test_task_totals_completed_open_and_completion_percentage(): void
    {
        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->count(3)->create();
        Task::factory()->forProject($this->project)->forColumn($this->todoColumn)->count(1)->create();

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.total_tasks', 4)
                ->where('analytics.completed_tasks', 3)
                ->where('analytics.open_tasks', 1)
                ->where('analytics.task_completion_percentage', 75));
    }

    public function test_tasks_by_board_column_breakdown(): void
    {
        Task::factory()->forProject($this->project)->forColumn($this->todoColumn)->count(2)->create();
        Task::factory()->forProject($this->project)->forColumn($this->inProgressColumn)->count(1)->create();
        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->count(3)->create();

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.tasks_by_column.0.name', 'To Do')
                ->where('analytics.tasks_by_column.0.count', 2)
                ->where('analytics.tasks_by_column.1.name', 'In Progress')
                ->where('analytics.tasks_by_column.1.count', 1)
                ->where('analytics.tasks_by_column.2.name', 'Done')
                ->where('analytics.tasks_by_column.2.count', 3)
                ->where('analytics.tasks_by_column.2.is_done', true));
    }

    public function test_overdue_task_count_excludes_done_and_future_due_dates(): void
    {
        Task::factory()->forProject($this->project)->forColumn($this->todoColumn)->withDueDate(now()->subDays(2)->toDateString())->create();
        Task::factory()->forProject($this->project)->forColumn($this->todoColumn)->withDueDate(now()->addDays(2)->toDateString())->create();
        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->withDueDate(now()->subDays(2)->toDateString())->create();
        Task::factory()->forProject($this->project)->forColumn($this->todoColumn)->create();

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page->where('analytics.overdue_tasks', 1));
    }

    public function test_tasks_by_assignee_breakdown_includes_unassigned_bucket(): void
    {
        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->assignedTo($this->projectMember)->count(2)->create();
        Task::factory()->forProject($this->project)->forColumn($this->todoColumn)->create();

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.tasks_by_assignee.0.name', $this->projectMember->name)
                ->where('analytics.tasks_by_assignee.0.count', 2)
                ->where('analytics.tasks_by_assignee.1.name', 'Unassigned')
                ->where('analytics.tasks_by_assignee.1.count', 1));
    }

    public function test_meeting_totals_upcoming_and_past(): void
    {
        Meeting::factory()->forProject($this->project)->createdBy($this->owner)->scheduledAt('2020-01-01 10:00:00')->create(['duration_minutes' => 30]);
        Meeting::factory()->forProject($this->project)->createdBy($this->owner)->scheduledAt(now()->addWeek()->toDateTimeString())->create();

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.total_meetings', 2)
                ->where('analytics.upcoming_meetings', 1)
                ->where('analytics.past_meetings', 1));
    }

    public function test_meeting_date_range_filter(): void
    {
        Meeting::factory()->forProject($this->project)->createdBy($this->owner)->scheduledAt('2024-06-10 10:00:00')->create(['duration_minutes' => 30]);
        Meeting::factory()->forProject($this->project)->createdBy($this->owner)->scheduledAt('2023-01-10 10:00:00')->create(['duration_minutes' => 30]);

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute(['from' => '2024-06-01', 'to' => '2024-06-30']))
            ->assertInertia(fn ($page) => $page->where('analytics.total_meetings', 1));
    }

    public function test_project_filter_scopes_all_metrics_to_one_project(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Zeus']);
        $otherDone = BoardColumn::query()->where('project_id', $otherProject->id)->where('name', 'Done')->firstOrFail();

        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->count(2)->create();
        Task::factory()->forProject($otherProject)->forColumn($otherDone)->count(5)->create();

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute(['project_id' => $this->project->id]))
            ->assertInertia(fn ($page) => $page
                ->where('analytics.total_tasks', 2)
                ->where('analytics.projects.0.id', $this->project->id));
    }

    public function test_requesting_an_inaccessible_project_id_returns_empty_analytics(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Zeus']);
        $otherDone = BoardColumn::query()->where('project_id', $otherProject->id)->where('name', 'Done')->firstOrFail();
        Task::factory()->forProject($otherProject)->forColumn($otherDone)->count(5)->create();

        $this->actingAs($this->projectMember)
            ->get($this->analyticsRoute(['project_id' => $otherProject->id]))
            ->assertInertia(fn ($page) => $page
                ->where('analytics.total_tasks', 0)
                ->where('analytics.projects', []));
    }

    public function test_workspace_owner_sees_aggregate_analytics_across_all_projects(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Zeus']);
        $otherDone = BoardColumn::query()->where('project_id', $otherProject->id)->where('name', 'Done')->firstOrFail();

        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->count(2)->create();
        Task::factory()->forProject($otherProject)->forColumn($otherDone)->count(3)->create();

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.total_tasks', 5)
                ->where('analytics.total_projects', 2));
    }

    public function test_project_member_only_sees_their_own_project_data(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Zeus']);
        $otherDone = BoardColumn::query()->where('project_id', $otherProject->id)->where('name', 'Done')->firstOrFail();

        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->count(2)->create();
        Task::factory()->forProject($otherProject)->forColumn($otherDone)->count(9)->create();

        $this->actingAs($this->projectMember)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.total_tasks', 2)
                ->where('analytics.total_projects', 1));
    }

    public function test_unassigned_workspace_member_sees_empty_state_analytics(): void
    {
        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->count(4)->create();

        $this->actingAs($this->unrelatedMember)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.total_tasks', 0)
                ->where('analytics.total_projects', 0)
                ->where('analytics.task_completion_percentage', 0)
                ->where('projects', []));
    }

    public function test_cross_workspace_data_never_appears(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();
        $otherProject = Project::factory()->forWorkspace($otherWorkspace)->create();
        $otherDone = BoardColumn::query()->where('project_id', $otherProject->id)->where('name', 'Done')->firstOrFail();
        Task::factory()->forProject($otherProject)->forColumn($otherDone)->count(10)->create();

        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->create();

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page->where('analytics.total_tasks', 1));
    }

    public function test_a_non_member_of_the_other_workspace_cannot_load_its_analytics(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();

        $this->actingAs($this->owner)
            ->get(route('workspace.analytics.index', ['workspace' => $otherWorkspace]))
            ->assertNotFound();
    }

    public function test_project_summary_includes_projects_with_zero_tasks(): void
    {
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Empty project']);

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page->has('analytics.projects', 2));
    }
}
