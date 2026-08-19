<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\DeleteTaskTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\Modules\Workspace\Data\ClientPermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssistantDeleteTaskToolTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Testing Project']);
    }

    private function context(?User $user = null): ToolContext
    {
        return new ToolContext(($user ?? $this->owner)->refresh(), $this->workspace->fresh());
    }

    private function task(string $title = 'Testing UI UX'): Task
    {
        return Task::factory()->create([
            'title' => $title,
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $this->project->boardColumns()->value('id'),
        ]);
    }

    public function test_a_task_can_be_deleted(): void
    {
        $task = $this->task();

        $result = app(DeleteTaskTool::class)->execute(['task_id' => $task->id], $this->context());

        $this->assertTrue($result['success']);
        $this->assertSame('Testing UI UX', $result['deleted_task']['title']);
        $this->assertStringContainsString('Deleted "Testing UI UX"', $result['message']);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_deleting_is_recorded_in_the_audit_log(): void
    {
        $task = $this->task();

        app(DeleteTaskTool::class)->execute(['task_id' => $task->id], $this->context());

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'action' => AuditAction::TASK_DELETED->value,
        ]);
    }

    public function test_the_confirmation_card_warns_it_cannot_be_undone(): void
    {
        $task = $this->task();
        TaskComment::factory()->count(2)->create(['task_id' => $task->id, 'user_id' => $this->owner->id]);

        $details = app(DeleteTaskTool::class)->confirmationDetails(['task_id' => $task->id], $this->context());

        $this->assertSame('Testing UI UX', $details['task']);
        $this->assertSame('Testing Project', $details['project']);
        $this->assertSame('2 comments', $details['also_deletes']);
        $this->assertStringContainsString('cannot be undone', $details['warning']);
    }

    public function test_deleting_always_asks_first(): void
    {
        $this->assertTrue(app(DeleteTaskTool::class)->requiresConfirmation());
    }

    public function test_an_already_deleted_task_reports_cleanly(): void
    {
        $task = $this->task();
        $id = $task->id;
        $task->delete();

        $result = app(DeleteTaskTool::class)->execute(['task_id' => $id], $this->context());

        $this->assertFalse($result['success']);
        $this->assertSame('task_not_found', $result['error_code']);
        $this->assertStringContainsString('already have been deleted', $result['error']);
    }

    public function test_a_task_in_a_project_the_user_cannot_see_is_untouchable(): void
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

        $result = app(DeleteTaskTool::class)->execute(['task_id' => $task->id], $this->context($member));

        $this->assertFalse($result['success']);
        $this->assertSame('task_not_found', $result['error_code']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_a_plain_project_member_cannot_delete(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $task = $this->task();

        $result = app(DeleteTaskTool::class)->execute(['task_id' => $task->id], $this->context($member));

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_a_client_can_never_delete_a_task(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'permissions' => [
                ClientPermission::BoardView->value => true,
                ClientPermission::TasksRequest->value => true,
                ClientPermission::TasksClose->value => true,
            ],
        ]);

        $client = User::factory()->create();
        $this->workspace->users()->attach($client->id, [
            'role' => UserRole::CLIENT->value,
            'workspace_role_id' => $role->id,
        ]);
        $this->project->members()->attach($client->id, ['role' => ProjectRole::MEMBER->value]);

        $task = $this->task();

        $result = app(DeleteTaskTool::class)->execute(['task_id' => $task->id], $this->context($client));

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_the_tool_is_registered_for_the_assistant(): void
    {
        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->context()),
        );

        $this->assertContains('delete_task', $names);
    }

    public function test_deleting_and_marking_done_are_separate_tools(): void
    {
        $deleteTool = app(ToolRegistry::class)->get('delete_task');
        $updateTool = app(ToolRegistry::class)->get('update_task');

        $this->assertNotNull($deleteTool);
        $this->assertNotNull($updateTool);

        /* The description has to steer the model away from swapping one for the other. */
        $this->assertStringContainsString('marking something done is update_task', $deleteTool->description());
        $this->assertArrayNotHasKey('column', $deleteTool->parameters()['properties']);
    }
}
