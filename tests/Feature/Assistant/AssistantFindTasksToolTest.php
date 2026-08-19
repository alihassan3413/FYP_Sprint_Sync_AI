<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\FindTasksTool;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssistantFindTasksToolTest extends TestCase
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

        $this->owner = User::factory()->create(['name' => 'Ada Owner']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'CIG Florida']);
        $this->todoColumn = $this->project->boardColumns()->where('position', 0)->firstOrFail();
        $this->doneColumn = $this->project->boardColumns()->where('is_done', true)->firstOrFail();
    }

    private function context(?User $user = null): ToolContext
    {
        return new ToolContext(($user ?? $this->owner)->refresh(), $this->workspace->fresh());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(string $title, array $attributes = [], ?Project $project = null): Task
    {
        $project ??= $this->project;

        return Task::factory()->create([
            'title' => $title,
            'project_id' => $project->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $project->boardColumns()->where('position', 0)->value('id'),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function find(array $args, ?User $user = null): array
    {
        return app(FindTasksTool::class)->execute($args, $this->context($user));
    }

    public function test_a_loosely_described_task_is_found(): void
    {
        $task = $this->task('UI/UX modification');
        $this->task('Write onboarding copy');

        $result = $this->find(['query' => 'UI UX']);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total_matches']);
        $this->assertFalse($result['needs_disambiguation']);
        $this->assertSame($task->id, $result['best_match_task_id']);
        $this->assertSame('CIG Florida', $result['tasks'][0]['project_name']);
        $this->assertGreaterThanOrEqual(85, $result['tasks'][0]['match_confidence']);
    }

    public function test_several_matches_are_returned_for_the_user_to_choose_from(): void
    {
        $this->task('UI/UX modification');
        $this->task('UI/UX modification for the client portal');
        $this->task('Unrelated billing work');

        $result = $this->find(['query' => 'UI UX modification']);

        $this->assertSame(2, $result['total_matches']);
        $this->assertTrue($result['needs_disambiguation']);
        $this->assertNull($result['best_match_task_id']);
        $this->assertStringContainsString('ask which one', $result['next_step']);
    }

    public function test_matches_come_back_ranked_by_confidence(): void
    {
        $this->task('Checkout bug on mobile Safari');
        $exact = $this->task('Checkout bug');

        $result = $this->find(['query' => 'checkout bug']);

        $this->assertSame($exact->id, $result['tasks'][0]['task_id']);
        $this->assertGreaterThanOrEqual($result['tasks'][1]['match_confidence'], $result['tasks'][0]['match_confidence']);
    }

    public function test_a_weak_but_plausible_match_is_still_offered(): void
    {
        $this->task('Redesign the dashboard header');

        $result = $this->find(['query' => 'dashbord']);

        $this->assertSame(1, $result['total_matches']);
        $this->assertGreaterThanOrEqual(25, $result['tasks'][0]['match_confidence']);
    }

    public function test_nothing_found_returns_suggestions_instead_of_a_dead_end(): void
    {
        $this->task('Write onboarding copy');

        $result = $this->find(['query' => 'payment gateway migration']);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['total_matches']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertStringContainsString('No task in', $result['message']);
        $this->assertStringContainsString('create', $result['next_step']);
    }

    public function test_finished_work_is_out_of_scope_by_default(): void
    {
        $this->task('UI/UX modification', [
            'board_column_id' => $this->doneColumn->id,
            'completed_at' => now(),
        ]);

        $this->assertSame(0, $this->find(['query' => 'ui ux'])['total_matches']);
        $this->assertSame(1, $this->find(['query' => 'ui ux', 'status' => 'all'])['total_matches']);
        $this->assertSame(1, $this->find(['query' => 'ui ux', 'status' => 'done'])['total_matches']);
    }

    public function test_the_search_can_be_narrowed_to_a_named_project(): void
    {
        $other = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Acme Internal']);

        $this->task('UI/UX modification');
        $this->task('UI/UX modification', [], $other);

        $result = $this->find(['query' => 'ui ux', 'project_name' => 'cig florida']);

        $this->assertSame(1, $result['total_matches']);
        $this->assertSame('CIG Florida', $result['tasks'][0]['project_name']);
    }

    public function test_an_ambiguous_project_name_asks_rather_than_guesses(): void
    {
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'CIG Texas']);
        $this->task('UI/UX modification');

        $result = $this->find(['query' => 'ui ux', 'project_name' => 'CIG']);

        $this->assertFalse($result['success']);
        $this->assertSame('project_ambiguous', $result['error_code']);
        $this->assertCount(2, $result['projects']);
    }

    public function test_tasks_can_be_filtered_by_who_holds_them(): void
    {
        $rana = User::factory()->create(['name' => 'Rana Dev']);
        $this->workspace->users()->attach($rana->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($rana->id, ['role' => ProjectRole::MEMBER->value]);

        $hers = $this->task('UI/UX modification', ['assigned_to' => $rana->id]);
        $this->task('UI/UX polish');

        $byName = $this->find(['query' => 'ui ux', 'assignee' => 'rana']);
        $unassigned = $this->find(['query' => 'ui ux', 'assignee' => 'unassigned']);

        $this->assertSame(1, $byName['total_matches']);
        $this->assertSame($hers->id, $byName['tasks'][0]['task_id']);
        $this->assertSame(1, $unassigned['total_matches']);
        $this->assertSame('UI/UX polish', $unassigned['tasks'][0]['title']);
    }

    public function test_listing_without_a_query_returns_recent_work(): void
    {
        $this->task('First task');
        $this->task('Second task');

        $result = $this->find([]);

        $this->assertSame(2, $result['total_matches']);
        $this->assertTrue($result['needs_disambiguation']);
    }

    public function test_the_tool_only_searches_projects_the_user_can_see(): void
    {
        $hidden = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Secret']);
        $this->task('UI/UX modification', [], $hidden);
        $this->task('UI/UX modification');

        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $result = $this->find(['query' => 'ui ux'], $member);

        $this->assertSame(1, $result['total_matches']);
        $this->assertSame('CIG Florida', $result['tasks'][0]['project_name']);
    }

    public function test_results_carry_the_context_needed_to_act(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create(['name' => 'Sprint 3']);
        $rana = User::factory()->create(['name' => 'Rana Dev']);
        $this->workspace->users()->attach($rana->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($rana->id, ['role' => ProjectRole::MEMBER->value]);

        $this->task('UI/UX modification', [
            'assigned_to' => $rana->id,
            'sprint_id' => $sprint->id,
            'due_date' => now()->subDays(2)->toDateString(),
        ]);

        $task = $this->find(['query' => 'ui ux'])['tasks'][0];

        $this->assertSame('Rana Dev', $task['assignee_name']);
        $this->assertSame('Sprint 3', $task['sprint_name']);
        $this->assertSame($this->todoColumn->name, $task['column']);
        $this->assertTrue($task['is_overdue']);
        $this->assertFalse($task['is_done']);
        $this->assertStringContainsString('task=', $task['url']);
    }

    public function test_a_user_with_no_projects_gets_a_clear_answer(): void
    {
        $outsider = User::factory()->create();
        $this->workspace->users()->attach($outsider->id, ['role' => UserRole::MEMBER->value]);

        $result = $this->find(['query' => 'anything'], $outsider);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['tasks']);
        $this->assertStringContainsString('no project here whose tasks you can see', $result['message']);
    }
}
