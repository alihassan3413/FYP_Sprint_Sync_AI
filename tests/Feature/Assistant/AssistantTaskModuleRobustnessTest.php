<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\CreateTaskTool;
use App\Modules\Assistant\Tools\FindTasksTool;
use App\Modules\Assistant\Tools\UpdateTaskTool;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The awkward inputs a real conversation produces: odd spacing, punctuation,
 * long strings, boundary values, and requests that arrive slightly too late.
 */
final class AssistantTaskModuleRobustnessTest extends TestCase
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
            'workspace_id' => $project->workspace_id,
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

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function create(array $args, ?User $user = null): array
    {
        /*
         * These tests are about assignees, sprints, audit trails and duplicates,
         * not about where on the board a task lands. create_task asks which
         * column to use when a project has more than one, so a default is
         * supplied here; the question itself is covered by
         * AssistantCreateTaskPlacementTest.
         */
        $args += ['board_column' => 'default'];

        return app(CreateTaskTool::class)->execute($args, $this->context($user));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function update(array $args, ?User $user = null): array
    {
        return app(UpdateTaskTool::class)->execute($args, $this->context($user));
    }

    /* ---------------------------------------------------------------- finding */

    public function test_messy_spacing_and_punctuation_still_find_the_task(): void
    {
        $task = $this->task('UI/UX modification');

        foreach (['  UI   UX  ', 'ui-ux', 'UI & UX', 'ui/ux', 'UI, UX!'] as $query) {
            $result = $this->find(['query' => $query]);

            $this->assertSame(1, $result['total_matches'], "Query \"{$query}\" found nothing.");
            $this->assertSame($task->id, $result['tasks'][0]['task_id']);
        }
    }

    public function test_a_query_of_only_punctuation_finds_nothing_without_erroring(): void
    {
        $this->task('UI/UX modification');

        $result = $this->find(['query' => '???']);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['total_matches']);
    }

    public function test_a_very_long_query_is_handled(): void
    {
        $this->task('UI/UX modification');

        $result = $this->find(['query' => str_repeat('ui ux ', 20)]);

        $this->assertTrue($result['success']);
    }

    public function test_a_task_can_be_found_by_words_in_its_description(): void
    {
        $task = $this->task('Sprint 4 cleanup', ['description' => 'Rework the checkout flow on mobile']);
        $this->task('Something else entirely');

        $result = $this->find(['query' => 'checkout flow']);

        $this->assertSame($task->id, $result['tasks'][0]['task_id']);
    }

    public function test_the_result_limit_is_clamped_to_a_sane_range(): void
    {
        foreach (range(1, 12) as $index) {
            $this->task("UI UX item {$index}");
        }

        $this->assertCount(1, $this->find(['query' => 'ui ux item', 'limit' => 0])['tasks']);
        $this->assertCount(3, $this->find(['query' => 'ui ux item', 'limit' => 3])['tasks']);
        $this->assertCount(10, $this->find(['query' => 'ui ux item', 'limit' => 99])['tasks']);
    }

    public function test_total_matches_reports_beyond_what_is_returned(): void
    {
        foreach (range(1, 12) as $index) {
            $this->task("UI UX item {$index}");
        }

        $result = $this->find(['query' => 'ui ux item', 'limit' => 3]);

        $this->assertSame(12, $result['total_matches']);
        $this->assertSame(3, $result['returned']);
    }

    public function test_overdue_filtering_ignores_finished_work(): void
    {
        $this->task('Late and open', ['due_date' => now()->subDays(3)->toDateString()]);
        $this->task('Late but finished', [
            'due_date' => now()->subDays(3)->toDateString(),
            'board_column_id' => $this->doneColumn->id,
            'completed_at' => now(),
        ]);
        $this->task('On time', ['due_date' => now()->addDays(3)->toDateString()]);

        $result = $this->find(['status' => 'overdue']);

        $this->assertSame(1, $result['total_matches']);
        $this->assertSame('Late and open', $result['tasks'][0]['title']);
    }

    public function test_the_me_filter_resolves_to_the_current_user(): void
    {
        $mine = $this->task('Mine to do', ['assigned_to' => $this->owner->id]);
        $this->task('Someone elses');

        $result = $this->find(['assignee' => 'me']);

        $this->assertSame(1, $result['total_matches']);
        $this->assertSame($mine->id, $result['tasks'][0]['task_id']);
    }

    public function test_a_deleted_task_stops_being_findable(): void
    {
        $task = $this->task('UI/UX modification');

        $this->assertSame(1, $this->find(['query' => 'ui ux'])['total_matches']);

        $task->delete();

        $this->assertSame(0, $this->find(['query' => 'ui ux'])['total_matches']);
    }

    public function test_tasks_in_another_workspace_are_never_reachable(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy($this->owner)->create();
        $otherProject = Project::factory()->forWorkspace($otherWorkspace)->create();

        $this->task('UI/UX modification', [], $otherProject);

        $result = $this->find(['query' => 'ui ux']);

        $this->assertSame(0, $result['total_matches']);
    }

    public function test_a_wildcard_looking_query_is_treated_as_plain_text(): void
    {
        $this->task('UI/UX modification');

        foreach (['%', '%%', "' OR 1=1 --", '_'] as $query) {
            $result = $this->find(['query' => $query]);

            $this->assertTrue($result['success']);
            $this->assertSame(0, $result['total_matches'], "Query \"{$query}\" matched something it should not have.");
        }
    }

    public function test_identical_titles_are_both_offered_rather_than_picked_between(): void
    {
        $this->task('UI/UX modification');
        $this->task('UI/UX modification');

        $result = $this->find(['query' => 'UI/UX modification']);

        $this->assertSame(2, $result['total_matches']);
        $this->assertTrue($result['needs_disambiguation']);
        $this->assertNull($result['best_match_task_id']);
    }

    public function test_the_same_title_in_two_projects_is_disambiguated_by_project(): void
    {
        $other = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Acme Internal']);

        $this->task('UI/UX modification');
        $this->task('UI/UX modification', [], $other);

        $result = $this->find(['query' => 'ui ux modification']);

        $this->assertTrue($result['needs_disambiguation']);
        $this->assertEqualsCanonicalizing(
            ['CIG Florida', 'Acme Internal'],
            array_column($result['tasks'], 'project_name'),
        );
    }

    /* --------------------------------------------------------------- creating */

    public function test_a_title_is_trimmed_before_it_is_saved(): void
    {
        $result = $this->create(['title' => '   Padded title   ']);

        $this->assertTrue($result['success']);
        $this->assertSame('Padded title', $result['task']['title']);
    }

    public function test_an_empty_title_is_refused(): void
    {
        $result = $this->create(['title' => '   ']);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_title', $result['error_code']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_due_date_in_the_past_is_allowed_because_people_backfill(): void
    {
        $result = $this->create(['title' => 'Overdue on purpose', 'due_date' => now()->subWeek()->toDateString()]);

        $this->assertTrue($result['success']);
        $this->assertSame(now()->subWeek()->toDateString(), $result['task']['due_date']);
    }

    public function test_a_task_can_be_created_into_a_named_sprint(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->planned()->upcoming()->create(['name' => 'Sprint 12']);

        $result = $this->create(['title' => 'Planned work', 'sprint' => 'Sprint 12']);

        $this->assertTrue($result['success']);
        $this->assertSame($sprint->id, $result['task']['sprint_id']);
    }

    public function test_a_sprint_from_another_project_is_refused(): void
    {
        $other = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Acme Internal']);
        Sprint::factory()->forProject($other)->running()->create(['name' => 'Foreign Sprint']);

        $result = $this->create([
            'title' => 'Planned work',
            'project_id' => $this->project->id,
            'sprint' => 'Foreign Sprint',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('sprint_not_found', $result['error_code']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_creating_records_an_audit_entry(): void
    {
        $this->create(['title' => 'Audited work']);

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'action' => 'task.created',
        ]);
    }

    /* --------------------------------------------------------------- updating */

    public function test_an_update_with_nothing_to_change_says_so_without_touching_the_task(): void
    {
        $task = $this->task('UI/UX modification', ['due_date' => '2026-12-01']);

        $result = $this->update(['task_id' => $task->id]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Nothing to change', $result['message']);
        $this->assertSame('2026-12-01', $task->fresh()->due_date->toDateString());
    }

    public function test_moving_a_task_to_the_column_it_is_already_in_is_harmless(): void
    {
        $task = $this->task('UI/UX modification');

        $result = $this->update(['task_id' => $task->id, 'column' => $this->todoColumn->name]);

        $this->assertTrue($result['success']);
        $this->assertSame($this->todoColumn->id, $task->fresh()->board_column_id);
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_reopening_a_finished_task_clears_its_completion(): void
    {
        $task = $this->task('UI/UX modification', [
            'board_column_id' => $this->doneColumn->id,
            'completed_at' => now(),
        ]);

        $this->update(['task_id' => $task->id, 'column' => $this->todoColumn->name]);

        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_a_title_can_be_corrected_and_is_trimmed(): void
    {
        $task = $this->task('UI/UX modifcation');

        $result = $this->update(['task_id' => $task->id, 'title' => '  UI/UX modification  ']);

        $this->assertTrue($result['success']);
        $this->assertSame('UI/UX modification', $task->fresh()->title);
        $this->assertStringContainsString('renamed it', $result['message']);
    }

    public function test_a_description_can_be_replaced(): void
    {
        $task = $this->task('UI/UX modification', ['description' => 'Old notes']);

        $this->update(['task_id' => $task->id, 'description' => 'New notes']);

        $this->assertSame('New notes', $task->fresh()->description);
    }

    public function test_a_task_deleted_between_finding_and_updating_reports_cleanly(): void
    {
        $task = $this->task('UI/UX modification');
        $id = $task->id;
        $task->delete();

        $result = $this->update(['task_id' => $id, 'assignee' => 'Ada']);

        $this->assertFalse($result['success']);
        $this->assertSame('task_not_found', $result['error_code']);
    }

    public function test_a_missing_task_id_is_refused_rather_than_guessed(): void
    {
        $this->task('UI/UX modification');

        $result = $this->update(['assignee' => 'Ada']);

        $this->assertFalse($result['success']);
        $this->assertSame('task_not_found', $result['error_code']);
    }

    public function test_a_sprint_from_another_project_cannot_be_attached_on_update(): void
    {
        $other = Project::factory()->forWorkspace($this->workspace)->create();
        Sprint::factory()->forProject($other)->running()->create(['name' => 'Foreign Sprint']);

        $task = $this->task('UI/UX modification');

        $result = $this->update(['task_id' => $task->id, 'sprint' => 'Foreign Sprint']);

        $this->assertFalse($result['success']);
        $this->assertSame('sprint_not_found', $result['error_code']);
        $this->assertNull($task->fresh()->sprint_id);
    }

    public function test_a_client_can_never_be_given_a_task(): void
    {
        $client = User::factory()->create(['name' => 'Casey Client']);
        $this->workspace->users()->attach($client->id, ['role' => UserRole::CLIENT->value]);
        $this->project->members()->attach($client->id, ['role' => ProjectRole::MEMBER->value]);

        $task = $this->task('UI/UX modification');

        $result = $this->update(['task_id' => $task->id, 'assignee' => 'Casey']);

        $this->assertFalse($result['success']);
        $this->assertNull($task->fresh()->assigned_to);
    }

    public function test_assignment_survives_a_column_move_in_the_same_call(): void
    {
        $rana = User::factory()->create(['name' => 'Rana Dev']);
        $this->workspace->users()->attach($rana->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($rana->id, ['role' => ProjectRole::MEMBER->value]);

        $task = $this->task('UI/UX modification');

        $this->update(['task_id' => $task->id, 'assignee' => 'Rana', 'column' => 'done']);

        $fresh = $task->fresh();

        $this->assertSame($rana->id, $fresh->assigned_to);
        $this->assertSame($this->doneColumn->id, $fresh->board_column_id);
        $this->assertNotNull($fresh->completed_at);
    }
}
