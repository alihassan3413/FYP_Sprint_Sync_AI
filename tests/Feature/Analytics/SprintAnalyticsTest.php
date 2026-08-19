<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

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

final class SprintAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    private BoardColumn $todo;

    private BoardColumn $done;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Alpha']);

        $this->todo = BoardColumn::query()->where('project_id', $this->project->id)->orderBy('position')->firstOrFail();
        $this->done = BoardColumn::query()->where('project_id', $this->project->id)->where('is_done', true)->firstOrFail();
    }

    private function analyticsRoute(array $params = []): string
    {
        return route('workspace.analytics.index', array_merge(['workspace' => $this->workspace->slug], $params));
    }

    private function taskIn(BoardColumn $column, ?Sprint $sprint, ?User $assignee = null, ?Project $project = null): Task
    {
        return Task::factory()
            ->forProject($project ?? $this->project)
            ->forColumn($column)
            ->when($assignee !== null, fn ($f) => $f->assignedTo($assignee))
            ->create(['sprint_id' => $sprint?->id]);
    }

    public function test_the_current_sprint_chart_counts_only_that_sprints_tasks(): void
    {
        $current = Sprint::factory()->forProject($this->project)->current()->create(['name' => 'Sprint 4']);
        $past = Sprint::factory()->forProject($this->project)->past()->create();

        $this->taskIn($this->done, $current);
        $this->taskIn($this->todo, $current);
        $this->taskIn($this->todo, $current);
        $this->taskIn($this->done, $past);
        $this->taskIn($this->todo, null);

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.sprint_progress.has_sprint', true)
                ->where('analytics.sprint_progress.total_tasks', 3)
                ->where('analytics.sprint_progress.completed_tasks', 1)
                ->where('analytics.sprint_progress.open_tasks', 2)
                ->where('analytics.sprint_progress.completion_percentage', 33)
                ->where('analytics.sprint_progress.sprints.0.name', 'Sprint 4')
                ->where('analytics.total_tasks', 5));
    }

    public function test_the_column_breakdown_is_limited_to_the_current_sprint(): void
    {
        $current = Sprint::factory()->forProject($this->project)->current()->create();

        $this->taskIn($this->done, $current);
        $this->taskIn($this->todo, $current);
        $this->taskIn($this->todo, null);

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(function ($page) {
                $columns = collect($page->toArray()['props']['analytics']['sprint_progress']['tasks_by_column']);

                $this->assertSame(2, $columns->sum('count'));
                $this->assertSame(1, $columns->firstWhere('is_done', true)['count']);
            });
    }

    public function test_a_project_without_a_current_sprint_reports_no_sprint(): void
    {
        Sprint::factory()->forProject($this->project)->past()->create();
        $this->taskIn($this->todo, null);

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.sprint_progress.has_sprint', false)
                ->where('analytics.sprint_progress.total_tasks', 0)
                ->where('analytics.sprint_progress.completion_percentage', 0)
                ->where('analytics.sprint_progress.sprints', []));
    }

    public function test_an_explicit_sprint_filter_overrides_the_current_sprint(): void
    {
        $current = Sprint::factory()->forProject($this->project)->current()->create();
        $past = Sprint::factory()->forProject($this->project)->past()->create(['name' => 'Sprint 1']);

        $this->taskIn($this->todo, $current);
        $this->taskIn($this->done, $past);
        $this->taskIn($this->done, $past);

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute(['sprint_id' => $past->id]))
            ->assertInertia(fn ($page) => $page
                ->where('analytics.sprint_progress.total_tasks', 2)
                ->where('analytics.sprint_progress.completed_tasks', 2)
                ->where('analytics.sprint_progress.completion_percentage', 100)
                ->where('analytics.sprint_progress.sprints.0.name', 'Sprint 1'));
    }

    public function test_current_sprints_across_multiple_projects_are_combined(): void
    {
        $beta = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Beta']);
        $betaDone = BoardColumn::query()->where('project_id', $beta->id)->where('is_done', true)->firstOrFail();

        $alphaSprint = Sprint::factory()->forProject($this->project)->current()->create();
        $betaSprint = Sprint::factory()->forProject($beta)->current()->create();

        $this->taskIn($this->todo, $alphaSprint);
        $this->taskIn($betaDone, $betaSprint, null, $beta);

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.sprint_progress.total_tasks', 2)
                ->where('analytics.sprint_progress.completed_tasks', 1)
                ->has('analytics.sprint_progress.sprints', 2));
    }

    public function test_a_project_filter_narrows_the_sprint_chart(): void
    {
        $beta = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Beta']);
        $betaTodo = BoardColumn::query()->where('project_id', $beta->id)->orderBy('position')->firstOrFail();

        $alphaSprint = Sprint::factory()->forProject($this->project)->current()->create();
        $betaSprint = Sprint::factory()->forProject($beta)->current()->create();

        $this->taskIn($this->todo, $alphaSprint);
        $this->taskIn($betaTodo, $betaSprint, null, $beta);
        $this->taskIn($betaTodo, $betaSprint, null, $beta);

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute(['project_id' => $beta->id]))
            ->assertInertia(fn ($page) => $page
                ->where('analytics.sprint_progress.total_tasks', 2)
                ->has('analytics.sprint_progress.sprints', 1));
    }

    public function test_a_sprint_from_an_inaccessible_project_is_not_reported(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $foreign = Project::factory()->forWorkspace($otherWorkspace)->create();
        $foreignColumn = BoardColumn::query()->where('project_id', $foreign->id)->orderBy('position')->firstOrFail();
        $foreignSprint = Sprint::factory()->forProject($foreign)->current()->create();

        $this->taskIn($foreignColumn, $foreignSprint, null, $foreign);

        $current = Sprint::factory()->forProject($this->project)->current()->create();
        $this->taskIn($this->todo, $current);

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute(['sprint_id' => $foreignSprint->id]))
            ->assertInertia(fn ($page) => $page->where('analytics.sprint_progress.has_sprint', false));
    }

    public function test_a_plain_member_sees_only_their_own_sprint_tasks(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $current = Sprint::factory()->forProject($this->project)->current()->create();

        $this->taskIn($this->done, $current, $member);
        $this->taskIn($this->todo, $current, $member);
        $this->taskIn($this->todo, $current);

        $this->actingAs($member)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.scope', 'personal')
                ->where('analytics.sprint_progress.total_tasks', 2)
                ->where('analytics.sprint_progress.completed_tasks', 1));
    }

    public function test_the_analytics_page_lists_selectable_sprints(): void
    {
        Sprint::factory()->forProject($this->project)->current()->create(['name' => 'Sprint 9']);

        $this->actingAs($this->owner)
            ->get($this->analyticsRoute())
            ->assertInertia(fn ($page) => $page
                ->has('sprints', 1)
                ->where('sprints.0.name', 'Sprint 9')
                ->where('sprints.0.is_current', true));
    }
}
