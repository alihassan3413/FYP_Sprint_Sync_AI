<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Actions\ResolveConversationWorkspace;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Support\ToolArgumentValidator;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\CreateTaskTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AssistantCreateTaskToolTest extends TestCase
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
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Apollo']);
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user;
    }

    private function contextFor(User $user, ?Workspace $workspace = null): ToolContext
    {
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'workspace_id' => ($workspace ?? $this->workspace)->id,
        ]);

        return app(ResolveConversationWorkspace::class)->contextFor($conversation, $user->refresh());
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function create(User $user, array $args, ?Workspace $workspace = null): array
    {
        /*
         * These tests are about assignees, sprints, audit trails and duplicates,
         * not about where on the board a task lands. create_task asks which
         * column to use when a project has more than one, so a default is
         * supplied here; the question itself is covered by
         * AssistantCreateTaskPlacementTest.
         */
        $args += ['board_column' => 'default'];

        return app(CreateTaskTool::class)->execute($args, $this->contextFor($user, $workspace));
    }

    public function test_an_owner_can_create_a_task_in_an_accessible_project(): void
    {
        $result = $this->create($this->owner, [
            'project_id' => $this->project->id,
            'title' => 'Fix the login screen',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Apollo', $result['task']['project_name']);
        $this->assertNull($result['task']['assignee_name']);
        $this->assertStringContainsString('?task=', $result['url']);

        $task = Task::query()->firstOrFail();

        $this->assertSame('Fix the login screen', $task->title);
        $this->assertSame($this->project->id, $task->project_id);
        $this->assertSame($this->workspace->id, $task->workspace_id);
        $this->assertNull($task->assigned_to);
    }

    public function test_a_new_task_lands_in_the_first_default_column(): void
    {
        $this->create($this->owner, ['project_id' => $this->project->id, 'title' => 'Anything']);

        $expected = $this->project->boardColumns()->where('is_default', true)->orderBy('position')->value('id');

        $this->assertSame($expected, Task::query()->firstOrFail()->board_column_id);
    }

    public function test_a_task_can_be_assigned_by_email(): void
    {
        $assignee = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($assignee->id, ['role' => ProjectRole::MEMBER->value]);

        $result = $this->create($this->owner, [
            'project_id' => $this->project->id,
            'title' => 'Assigned work',
            'assignee_email' => $assignee->email,
            'due_date' => '2026-12-01',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame($assignee->name, $result['task']['assignee_name']);
        $this->assertSame('2026-12-01', $result['task']['due_date']);
        $this->assertSame($assignee->id, Task::query()->firstOrFail()->assigned_to);
    }

    public function test_an_assignee_outside_the_project_is_rejected(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $result = $this->create($this->owner, [
            'project_id' => $this->project->id,
            'title' => 'Nope',
            'assignee_email' => $outsider->email,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('assignee_not_on_project', $result['error_code']);
        $this->assertStringContainsString('add_project_member', $result['next_step']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_an_unknown_assignee_email_is_rejected(): void
    {
        $result = $this->create($this->owner, [
            'project_id' => $this->project->id,
            'title' => 'Nope',
            'assignee_email' => 'ghost@example.com',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('assignee_not_found', $result['error_code']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_project_manager_can_create_a_task_in_their_project(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $result = $this->create($manager, ['project_id' => $this->project->id, 'title' => 'Manager task']);

        $this->assertTrue($result['success']);
        $this->assertSame(1, Task::query()->count());
    }

    public function test_a_plain_project_member_cannot_create_a_task(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $result = $this->create($member, ['project_id' => $this->project->id, 'title' => 'Nope']);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_an_unassigned_workspace_member_cannot_reach_the_project(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $result = $this->create($outsider, ['project_id' => $this->project->id, 'title' => 'Nope']);

        $this->assertFalse($result['success']);
        $this->assertSame('no_projects', $result['error_code']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_project_in_another_workspace_is_never_reachable(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $other->users()->attach($this->owner->id, ['role' => UserRole::OWNER->value]);
        $foreign = Project::factory()->forWorkspace($other)->create();

        $result = $this->create($this->owner, ['project_id' => $foreign->id, 'title' => 'Cross tenant']);

        $this->assertFalse($result['success']);
        $this->assertSame('project_not_found', $result['error_code']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_the_tool_requires_confirmation(): void
    {
        $this->assertTrue(app(CreateTaskTool::class)->requiresConfirmation());
    }

    public function test_a_plain_member_is_not_offered_the_tool(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->contextFor($member)),
        );

        $this->assertNotContains('create_task', $names);
    }

    public function test_a_project_manager_is_offered_the_tool(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->contextFor($manager)),
        );

        $this->assertContains('create_task', $names);
    }

    public function test_the_tool_is_not_offered_without_a_workspace_context(): void
    {
        $this->assertFalse(app(CreateTaskTool::class)->authorize(new ToolContext($this->owner, null)));
    }

    public function test_a_missing_title_fails_schema_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate(
            app(CreateTaskTool::class),
            ['project_id' => $this->project->id],
        );
    }

    public function test_an_invalid_due_date_fails_schema_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate(
            app(CreateTaskTool::class),
            ['project_id' => $this->project->id, 'title' => 'Valid', 'due_date' => 'next tuesday-ish'],
        );
    }

    public function test_a_malformed_assignee_email_fails_schema_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate(
            app(CreateTaskTool::class),
            ['project_id' => $this->project->id, 'title' => 'Valid', 'assignee_email' => 'not-an-email'],
        );
    }

    public function test_valid_arguments_pass_schema_validation(): void
    {
        $validated = app(ToolArgumentValidator::class)->validate(
            app(CreateTaskTool::class),
            [
                'project_id' => $this->project->id,
                'title' => 'Valid title',
                'due_date' => '2026-12-01',
                'injected' => 'dropped',
            ],
        );

        $this->assertSame('Valid title', $validated['title']);
        $this->assertArrayNotHasKey('injected', $validated);
    }

    public function test_creating_a_task_records_an_audit_entry(): void
    {
        $this->create($this->owner, ['project_id' => $this->project->id, 'title' => 'Audited task']);

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'user_id' => $this->owner->id,
            'action' => 'task.created',
        ]);
    }
}
