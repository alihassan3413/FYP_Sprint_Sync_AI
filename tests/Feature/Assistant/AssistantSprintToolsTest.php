<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\CreateTaskTool;
use App\Modules\Assistant\Tools\ManageSprintTool;
use App\Modules\Assistant\Tools\SprintReportTool;
use App\Modules\Projects\Data\SprintCarryOver;
use App\Modules\Projects\Data\SprintStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssistantSprintToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    private BoardColumn $todoColumn;

    private BoardColumn $doneColumn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create(['name' => 'Alpha']);
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Acme Redesign']);
        $this->todoColumn = $this->project->boardColumns()->where('position', 0)->firstOrFail();
        $this->doneColumn = $this->project->boardColumns()->where('is_done', true)->firstOrFail();
    }

    private function context(?User $user = null): ToolContext
    {
        return new ToolContext(($user ?? $this->owner)->refresh(), $this->workspace->fresh());
    }

    private function runningSprint(): Sprint
    {
        return Sprint::factory()->forProject($this->project)->running()->create([
            'name' => 'Sprint 9',
            'goal' => 'Ship the new checkout',
            'starts_on' => now()->subDays(4)->toDateString(),
            'ends_on' => now()->addDays(5)->toDateString(),
        ]);
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

    public function test_the_report_tool_describes_the_running_sprint(): void
    {
        $sprint = $this->runningSprint();

        $this->task($sprint, ['completed_at' => now()->subDay()]);
        $this->task($sprint);
        $this->task($sprint, ['due_date' => now()->subDays(2)->toDateString(), 'title' => 'Overdue thing']);

        $result = app(SprintReportTool::class)->execute([], $this->context());

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['sprints']);

        $report = $result['sprints'][0];

        $this->assertSame('Sprint 9', $report['name']);
        $this->assertSame('Acme Redesign', $report['project_name']);
        $this->assertSame(SprintStatus::Active->value, $report['status']);
        $this->assertSame(3, $report['work']['total_tasks']);
        $this->assertSame(1, $report['work']['completed_tasks']);
        $this->assertSame(5, $report['dates']['days_remaining']);
        $this->assertSame('Overdue thing', $report['blockers'][0]['title']);
        $this->assertNotEmpty($report['summary']);
        $this->assertArrayNotHasKey('burndown', $report);
    }

    public function test_the_report_tool_returns_the_burndown_on_request(): void
    {
        $sprint = $this->runningSprint();
        $this->task($sprint);

        $result = app(SprintReportTool::class)->execute(['include_burndown' => true], $this->context());

        $this->assertNotEmpty($result['sprints'][0]['burndown']);
        $this->assertArrayHasKey('remaining', $result['sprints'][0]['burndown'][0]);
        $this->assertArrayHasKey('ideal', $result['sprints'][0]['burndown'][0]);
    }

    public function test_the_report_tool_explains_when_nothing_is_running(): void
    {
        Sprint::factory()->forProject($this->project)->planned()->upcoming()->create();

        $result = app(SprintReportTool::class)->execute([], $this->context());

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['sprints']);
        $this->assertStringContainsString('No sprint is currently running', $result['message']);
    }

    public function test_the_report_tool_can_look_at_completed_sprints(): void
    {
        Sprint::factory()->forProject($this->project)->completed(7, 1)->past()->create(['name' => 'Sprint 8']);

        $result = app(SprintReportTool::class)->execute([
            'status' => SprintStatus::Completed->value,
        ], $this->context());

        $this->assertSame('Sprint 8', $result['sprints'][0]['name']);
        $this->assertSame(7, $result['sprints'][0]['work']['completed_tasks']);
        $this->assertSame(1, $result['sprints'][0]['work']['carried_over_task_count']);
    }

    public function test_the_report_tool_never_leaks_another_users_project(): void
    {
        $hidden = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Secret']);
        Sprint::factory()->forProject($hidden)->running()->create(['name' => 'Hidden sprint']);

        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->runningSprint();

        $result = app(SprintReportTool::class)->execute([], $this->context($member));

        $this->assertCount(1, $result['sprints']);
        $this->assertSame('Sprint 9', $result['sprints'][0]['name']);
    }

    public function test_the_assistant_can_plan_a_sprint_with_sensible_defaults(): void
    {
        $result = app(ManageSprintTool::class)->execute([
            'action' => 'create',
            'project_id' => $this->project->id,
            'name' => 'Sprint 1',
            'goal' => 'Foundations',
        ], $this->context());

        $this->assertTrue($result['success']);

        $sprint = Sprint::query()->where('name', 'Sprint 1')->firstOrFail();

        $this->assertSame(SprintStatus::Planned, $sprint->status);
        $this->assertSame(now()->toDateString(), $sprint->starts_on->toDateString());
        $this->assertSame(now()->addDays(13)->toDateString(), $sprint->ends_on->toDateString());
        $this->assertStringContainsString('not running yet', $result['message']);
    }

    public function test_planning_a_sprint_refuses_to_overlap_an_existing_one(): void
    {
        $this->runningSprint();

        $result = app(ManageSprintTool::class)->execute([
            'action' => 'create',
            'project_id' => $this->project->id,
            'name' => 'Sprint 10',
        ], $this->context());

        $this->assertFalse($result['success']);
        $this->assertSame('overlapping_sprint', $result['error_code']);
        $this->assertStringContainsString('Sprint 9', $result['error']);
    }

    public function test_the_assistant_can_start_and_complete_a_sprint(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->planned()->create([
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(6)->toDateString(),
        ]);

        $this->task($sprint);
        $this->task($sprint, ['completed_at' => now()]);

        $started = app(ManageSprintTool::class)->execute([
            'action' => 'start',
            'sprint_id' => $sprint->id,
        ], $this->context());

        $this->assertTrue($started['success']);
        $this->assertSame(2, $started['sprint']['committed_task_count']);

        $completed = app(ManageSprintTool::class)->execute([
            'action' => 'complete',
            'sprint_id' => $sprint->id,
            'carry_over' => SprintCarryOver::Backlog->value,
        ], $this->context());

        $this->assertTrue($completed['success']);
        $this->assertSame(1, $completed['sprint']['completed_task_count']);
        $this->assertSame(1, $completed['sprint']['carried_over_task_count']);
        $this->assertSame(SprintStatus::Completed->value, $completed['sprint']['status']);
    }

    public function test_the_assistant_surfaces_the_one_active_sprint_rule(): void
    {
        $this->runningSprint();

        $next = Sprint::factory()->forProject($this->project)->planned()->upcoming()->create(['name' => 'Sprint 10']);

        $result = app(ManageSprintTool::class)->execute([
            'action' => 'start',
            'sprint_id' => $next->id,
        ], $this->context());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Sprint 9', $result['error']);
        $this->assertSame(SprintStatus::Planned, $next->fresh()->status);
    }

    public function test_a_sprint_from_a_project_the_user_cannot_see_is_not_touchable(): void
    {
        $hidden = Project::factory()->forWorkspace($this->workspace)->create();
        $sprint = Sprint::factory()->forProject($hidden)->planned()->create();

        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MANAGER->value]);

        $result = app(ManageSprintTool::class)->execute([
            'action' => 'start',
            'sprint_id' => $sprint->id,
        ], $this->context($member));

        $this->assertFalse($result['success']);
        $this->assertSame('sprint_not_found', $result['error_code']);
    }

    public function test_the_confirmation_card_spells_out_what_completing_does(): void
    {
        $sprint = $this->runningSprint();
        $this->task($sprint);
        $this->task($sprint, ['completed_at' => now()]);

        $details = app(ManageSprintTool::class)->confirmationDetails([
            'action' => 'complete',
            'sprint_id' => $sprint->id,
            'carry_over' => SprintCarryOver::NextSprint->value,
        ], $this->context());

        $this->assertSame('Sprint 9', $details['sprint']);
        $this->assertStringContainsString('1 task(s) finished', $details['done']);
        $this->assertStringContainsString('next planned sprint', $details['unfinished']);
        $this->assertStringContainsString('cannot be undone', $details['note']);
    }

    public function test_a_task_can_be_created_straight_into_the_running_sprint(): void
    {
        $sprint = $this->runningSprint();

        $result = app(CreateTaskTool::class)->execute([
            'project_id' => $this->project->id,
            'title' => 'Wire up the payment step',
            'sprint' => 'current',
            /* Placement is not what this test is about; see AssistantCreateTaskPlacementTest. */
            'board_column' => 'default',
        ], $this->context());

        $this->assertTrue($result['success']);
        $this->assertSame($sprint->id, $result['task']['sprint_id']);
        $this->assertStringContainsString('Sprint 9', $result['message']);
    }

    public function test_asking_for_the_current_sprint_when_none_runs_is_explained(): void
    {
        $result = app(CreateTaskTool::class)->execute([
            'project_id' => $this->project->id,
            'title' => 'Wire up the payment step',
            'sprint' => 'current',
        ], $this->context());

        $this->assertFalse($result['success']);
        $this->assertSame('sprint_not_found', $result['error_code']);
        $this->assertStringContainsString('no running sprint', $result['error']);
        $this->assertDatabaseMissing('tasks', ['title' => 'Wire up the payment step']);
    }
}
