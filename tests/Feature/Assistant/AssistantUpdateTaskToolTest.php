<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\UpdateTaskTool;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AssistantUpdateTaskToolTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $rana;

    private Workspace $workspace;

    private Project $project;

    private BoardColumn $todoColumn;

    private BoardColumn $doneColumn;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->owner = User::factory()->create(['name' => 'Ada Owner']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'CIG Florida']);
        $this->todoColumn = $this->project->boardColumns()->where('position', 0)->firstOrFail();
        $this->doneColumn = $this->project->boardColumns()->where('is_done', true)->firstOrFail();

        $this->rana = User::factory()->create(['name' => 'Rana Dev', 'email' => 'rana@example.com']);
        $this->workspace->users()->attach($this->rana->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($this->rana->id, ['role' => ProjectRole::MEMBER->value]);
    }

    private function context(?User $user = null): ToolContext
    {
        return new ToolContext(($user ?? $this->owner)->refresh(), $this->workspace->fresh());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = []): Task
    {
        return Task::factory()->create([
            'title' => 'UI/UX modification',
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $this->todoColumn->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function update(array $args, ?User $user = null): array
    {
        return app(UpdateTaskTool::class)->execute($args, $this->context($user));
    }

    public function test_a_task_can_be_assigned_by_first_name(): void
    {
        $task = $this->task();

        $result = $this->update(['task_id' => $task->id, 'assignee' => 'rana']);

        $this->assertTrue($result['success']);
        $this->assertSame('Rana Dev', $result['task']['assignee_name']);
        $this->assertSame($this->rana->id, $task->fresh()->assigned_to);
        $this->assertStringContainsString('assigned it to Rana Dev', $result['message']);
    }

    public function test_a_task_can_be_assigned_by_email(): void
    {
        $task = $this->task();

        $result = $this->update(['task_id' => $task->id, 'assignee' => 'rana@example.com']);

        $this->assertTrue($result['success']);
        $this->assertSame($this->rana->id, $task->fresh()->assigned_to);
    }

    public function test_two_people_with_similar_names_are_never_guessed_between(): void
    {
        $rana2 = User::factory()->create(['name' => 'Rana Ahmed']);
        $this->workspace->users()->attach($rana2->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($rana2->id, ['role' => ProjectRole::MEMBER->value]);

        $task = $this->task();

        $result = $this->update(['task_id' => $task->id, 'assignee' => 'rana']);

        $this->assertFalse($result['success']);
        $this->assertSame('assignee_ambiguous', $result['error_code']);
        $this->assertCount(2, $result['people']);
        $this->assertNull($task->fresh()->assigned_to);
    }

    public function test_someone_outside_the_project_prompts_an_offer_to_add_them(): void
    {
        $outsider = User::factory()->create(['name' => 'Zed Outside']);
        $this->workspace->users()->attach($outsider->id, ['role' => UserRole::MEMBER->value]);

        $task = $this->task();

        $result = $this->update(['task_id' => $task->id, 'assignee' => 'Zed Outside']);

        $this->assertFalse($result['success']);
        $this->assertSame('assignee_not_on_project', $result['error_code']);
        $this->assertStringContainsString('add_project_member', $result['next_step']);
    }

    public function test_a_task_can_be_unassigned(): void
    {
        $task = $this->task(['assigned_to' => $this->rana->id]);

        $result = $this->update(['task_id' => $task->id, 'assignee' => 'unassigned']);

        $this->assertTrue($result['success']);
        $this->assertNull($task->fresh()->assigned_to);
        $this->assertStringContainsString('unassigned it', $result['message']);
    }

    public function test_untouched_fields_are_left_alone(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create();
        $task = $this->task([
            'due_date' => '2026-12-01',
            'description' => 'Original description',
            'sprint_id' => $sprint->id,
        ]);

        $this->update(['task_id' => $task->id, 'assignee' => 'rana']);

        $fresh = $task->fresh();

        $this->assertSame('2026-12-01', $fresh->due_date->toDateString());
        $this->assertSame('Original description', $fresh->description);
        $this->assertSame($sprint->id, $fresh->sprint_id);
        $this->assertSame('UI/UX modification', $fresh->title);
    }

    public function test_a_due_date_can_be_set_and_cleared(): void
    {
        $task = $this->task();

        $this->update(['task_id' => $task->id, 'due_date' => '2026-12-24']);
        $this->assertSame('2026-12-24', $task->fresh()->due_date->toDateString());

        $this->update(['task_id' => $task->id, 'due_date' => 'none']);
        $this->assertNull($task->fresh()->due_date);
    }

    public function test_an_unreadable_due_date_is_refused_without_touching_the_task(): void
    {
        $task = $this->task();

        $result = $this->update(['task_id' => $task->id, 'due_date' => 'sometime next quarter']);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_due_date', $result['error_code']);
        $this->assertNull($task->fresh()->due_date);
    }

    public function test_a_task_can_be_moved_into_the_running_sprint_and_back_out(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create(['name' => 'Sprint 3']);
        $task = $this->task();

        $this->update(['task_id' => $task->id, 'sprint' => 'current']);
        $this->assertSame($sprint->id, $task->fresh()->sprint_id);

        $this->update(['task_id' => $task->id, 'sprint' => 'backlog']);
        $this->assertNull($task->fresh()->sprint_id);
    }

    public function test_a_sprint_can_be_named_loosely(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->planned()->upcoming()->create(['name' => 'Sprint 12']);
        $task = $this->task();

        $this->update(['task_id' => $task->id, 'sprint' => 'sprint 12']);

        $this->assertSame($sprint->id, $task->fresh()->sprint_id);
    }

    public function test_asking_for_the_current_sprint_when_none_runs_is_explained(): void
    {
        $task = $this->task();

        $result = $this->update(['task_id' => $task->id, 'sprint' => 'current']);

        $this->assertFalse($result['success']);
        $this->assertSame('sprint_not_found', $result['error_code']);
        $this->assertStringContainsString('no running sprint', $result['error']);
    }

    public function test_a_task_can_be_marked_done_by_column_name(): void
    {
        $task = $this->task();

        $result = $this->update(['task_id' => $task->id, 'column' => 'done']);

        $this->assertTrue($result['success']);
        $this->assertSame($this->doneColumn->id, $task->fresh()->board_column_id);
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertTrue($result['task']['is_done']);
    }

    public function test_an_unknown_column_lists_the_real_ones(): void
    {
        $task = $this->task();

        $result = $this->update(['task_id' => $task->id, 'column' => 'shipped to production']);

        $this->assertFalse($result['success']);
        $this->assertSame('column_not_found', $result['error_code']);
        $this->assertNotEmpty($result['columns']);
    }

    public function test_several_changes_can_be_made_at_once(): void
    {
        $task = $this->task();

        $result = $this->update([
            'task_id' => $task->id,
            'assignee' => 'Rana',
            'due_date' => '2026-11-30',
            'column' => 'done',
        ]);

        $fresh = $task->fresh();

        $this->assertTrue($result['success']);
        $this->assertSame($this->rana->id, $fresh->assigned_to);
        $this->assertSame('2026-11-30', $fresh->due_date->toDateString());
        $this->assertSame($this->doneColumn->id, $fresh->board_column_id);
        $this->assertStringContainsString(' and ', $result['message']);
    }

    public function test_a_task_from_a_project_the_user_cannot_see_is_untouchable(): void
    {
        $hidden = Project::factory()->forWorkspace($this->workspace)->create();
        $task = Task::factory()->create([
            'project_id' => $hidden->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $hidden->boardColumns()->value('id'),
        ]);

        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MANAGER->value]);

        $result = $this->update(['task_id' => $task->id, 'assignee' => 'rana'], $member);

        $this->assertFalse($result['success']);
        $this->assertSame('task_not_found', $result['error_code']);
        $this->assertStringContainsString('find_tasks', $result['next_step']);
    }

    public function test_a_plain_project_member_cannot_reassign_work(): void
    {
        $task = $this->task();

        $result = $this->update(['task_id' => $task->id, 'assignee' => 'rana'], $this->rana);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertNull($task->fresh()->assigned_to);
    }

    public function test_the_assignee_can_still_move_their_own_task(): void
    {
        $task = $this->task(['assigned_to' => $this->rana->id]);

        $result = $this->update(['task_id' => $task->id, 'column' => 'done'], $this->rana);

        $this->assertTrue($result['success']);
        $this->assertSame($this->doneColumn->id, $task->fresh()->board_column_id);
    }

    public function test_the_confirmation_card_spells_out_the_change(): void
    {
        $task = $this->task();

        $details = app(UpdateTaskTool::class)->confirmationDetails([
            'task_id' => $task->id,
            'assignee' => 'Rana',
            'column' => 'done',
        ], $this->context());

        $this->assertSame('UI/UX modification', $details['task']);
        $this->assertSame('CIG Florida', $details['project']);
        $this->assertSame('Assign to Rana', $details['assignee']);
        $this->assertSame('Move to done', $details['column']);
    }

    public function test_the_tool_requires_confirmation(): void
    {
        $this->assertTrue(app(UpdateTaskTool::class)->requiresConfirmation());
    }
}
