<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Modules\Projects\Actions\BuildSprintReport;
use App\Modules\Projects\Data\SprintHealth;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class SprintReportTest extends TestCase
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

    private function report(Sprint $sprint)
    {
        return app(BuildSprintReport::class)->handle($sprint);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(Sprint $sprint, array $attributes = []): Task
    {
        return Task::factory()->create([
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'sprint_id' => $sprint->id,
            'board_column_id' => ($attributes['completed_at'] ?? null) !== null
                ? $this->doneColumn->id
                : $this->todoColumn->id,
            ...$attributes,
        ]);
    }

    /**
     * A 10 day sprint that started 5 days ago: the calendar is exactly half gone.
     */
    private function halfwaySprint(): Sprint
    {
        return Sprint::factory()->forProject($this->project)->running()->create([
            'name' => 'Sprint 9',
            'starts_on' => now()->subDays(4)->toDateString(),
            'ends_on' => now()->addDays(5)->toDateString(),
        ]);
    }

    public function test_a_sprint_keeping_pace_with_the_calendar_is_on_track(): void
    {
        $sprint = $this->halfwaySprint();

        $this->task($sprint, ['completed_at' => now()->subDay()]);
        $this->task($sprint, ['completed_at' => now()->subDay()]);
        $this->task($sprint);
        $this->task($sprint);

        $report = $this->report($sprint);

        $this->assertSame(50, $report->completion_percentage);
        $this->assertSame(50, $report->expected_percentage);
        $this->assertSame(0, $report->pace_delta);
        $this->assertSame(SprintHealth::OnTrack->value, $report->health);
        $this->assertSame(5, $report->days_remaining);
    }

    public function test_a_sprint_lagging_the_calendar_is_flagged(): void
    {
        $sprint = $this->halfwaySprint();

        $this->task($sprint, ['completed_at' => now()->subDay()]);

        foreach (range(1, 9) as $ignored) {
            $this->task($sprint);
        }

        $report = $this->report($sprint);

        $this->assertSame(10, $report->completion_percentage);
        $this->assertSame(-40, $report->pace_delta);
        $this->assertSame(SprintHealth::OffTrack->value, $report->health);
        $this->assertNotEmpty($report->recommendations);
    }

    public function test_a_sprint_past_its_end_date_with_work_left_is_overdue(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create([
            'starts_on' => now()->subDays(20)->toDateString(),
            'ends_on' => now()->subDays(6)->toDateString(),
        ]);

        $this->task($sprint);

        $report = $this->report($sprint);

        $this->assertSame(SprintHealth::Overdue->value, $report->health);
        $this->assertSame(0, $report->days_remaining);
        $this->assertStringContainsString('carry them over', implode(' ', $report->recommendations));
    }

    public function test_an_empty_planned_sprint_reports_that_it_needs_work(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->planned()->upcoming()->create();

        $report = $this->report($sprint);

        $this->assertSame(SprintHealth::NotStarted->value, $report->health);
        $this->assertSame(0, $report->total_tasks);
        $this->assertStringContainsString('Add tasks', implode(' ', $report->recommendations));
    }

    public function test_the_burndown_has_one_exact_point_per_elapsed_day(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create([
            'starts_on' => now()->subDays(2)->toDateString(),
            'ends_on' => now()->addDays(2)->toDateString(),
            'committed_task_count' => 4,
        ]);

        /* Four tasks created before the sprint, two finished on successive days. */
        foreach (range(1, 4) as $ignored) {
            $task = $this->task($sprint);
            $task->forceFill(['created_at' => Carbon::now()->subDays(3)])->save();
        }

        Task::query()->where('sprint_id', $sprint->id)->limit(1)->update([
            'completed_at' => now()->subDays(2)->endOfDay(),
            'board_column_id' => $this->doneColumn->id,
        ]);

        $report = $this->report($sprint);

        $this->assertCount(3, $report->burndown);
        $this->assertSame(now()->subDays(2)->toDateString(), $report->burndown[0]['date']);
        $this->assertSame(3, $report->burndown[0]['remaining']);
        $this->assertSame(3, $report->burndown[2]['remaining']);
        $this->assertSame(4.0, $report->burndown[0]['ideal']);
        $this->assertGreaterThan($report->burndown[2]['ideal'], $report->burndown[1]['ideal']);
    }

    public function test_the_report_breaks_work_down_by_person_column_and_blocker(): void
    {
        $sprint = $this->halfwaySprint();

        $assignee = User::factory()->create(['name' => 'Rana Dev']);
        $this->project->members()->attach($assignee->id, ['role' => 'member']);

        $this->task($sprint, ['assigned_to' => $assignee->id, 'completed_at' => now()->subDay()]);
        $this->task($sprint, ['assigned_to' => $assignee->id]);
        $this->task($sprint, ['due_date' => now()->subDays(3)->toDateString(), 'title' => 'Late thing']);

        $report = $this->report($sprint);

        $this->assertSame('Rana Dev', $report->workload[0]['name']);
        $this->assertSame(2, $report->workload[0]['total']);
        $this->assertSame(1, $report->workload[0]['completed']);

        $this->assertSame(1, $report->overdue_tasks);
        $this->assertSame('Late thing', $report->blockers[0]['title']);

        $this->assertSame(1, $report->unassigned_tasks);
        $this->assertSame(2, $report->column_breakdown[$this->todoColumn->name]);
    }

    public function test_scope_added_after_the_start_is_called_out(): void
    {
        $sprint = $this->halfwaySprint();
        $sprint->update(['committed_task_count' => 2]);

        $this->task($sprint);
        $this->task($sprint);
        $this->task($sprint);

        $report = $this->report($sprint);

        $this->assertSame(1, $report->scope_added);
        $this->assertStringContainsString('Scope grew by 1 task', implode(' ', $report->recommendations));
    }

    public function test_velocity_averages_the_projects_recent_completed_sprints(): void
    {
        Sprint::factory()->forProject($this->project)->completed(6)->create([
            'completed_at' => now()->subDays(30),
            'starts_on' => now()->subDays(44)->toDateString(),
            'ends_on' => now()->subDays(31)->toDateString(),
        ]);
        Sprint::factory()->forProject($this->project)->completed(4)->create([
            'completed_at' => now()->subDays(15),
            'starts_on' => now()->subDays(29)->toDateString(),
            'ends_on' => now()->subDays(16)->toDateString(),
        ]);

        $report = $this->report($this->halfwaySprint());

        $this->assertSame(5.0, $report->velocity_average);
    }

    public function test_a_completed_sprint_reports_its_frozen_result(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->completed(3, 2)->past()->create();

        $report = $this->report($sprint);

        $this->assertSame(SprintHealth::Done->value, $report->health);
        $this->assertSame(5, $report->total_tasks);
        $this->assertSame(3, $report->completed_tasks);
        $this->assertSame(2, $report->open_tasks);
        $this->assertSame(60, $report->completion_percentage);
        $this->assertSame(2, $report->carried_over_task_count);
        $this->assertStringContainsString('Finished with 3 done of 5 committed', implode(' ', $report->recommendations));
    }

    public function test_the_project_page_ships_the_running_sprints_report(): void
    {
        $sprint = $this->halfwaySprint();
        $this->task($sprint, ['completed_at' => now()->subDay()]);
        $this->task($sprint);

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', ['workspace' => $this->workspace->slug, 'project' => $this->project->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activeSprintReport.sprint_id', $sprint->id)
                ->where('activeSprintReport.health', SprintHealth::OnTrack->value)
                ->where('activeSprintReport.completion_percentage', 50)
                ->has('activeSprintReport.burndown')
                ->where('sprints.0.status', 'active')
                ->where('sprints.0.completed_task_count', 1));
    }

    public function test_the_project_page_has_no_report_when_nothing_is_running(): void
    {
        Sprint::factory()->forProject($this->project)->planned()->upcoming()->create();

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', ['workspace' => $this->workspace->slug, 'project' => $this->project->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('activeSprintReport', null));
    }

    public function test_cycle_time_is_averaged_over_completed_work(): void
    {
        $sprint = $this->halfwaySprint();

        $first = $this->task($sprint, ['completed_at' => now()]);
        $first->forceFill(['created_at' => now()->subDays(4)])->save();

        $second = $this->task($sprint, ['completed_at' => now()]);
        $second->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->assertSame(3.0, $this->report($sprint)->average_cycle_time_days);
    }
}
