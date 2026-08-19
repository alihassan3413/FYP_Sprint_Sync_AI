<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\CreateTaskTool;
use App\Modules\Assistant\Tools\FindTasksTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Assistant\Tools\UpdateTaskTool;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Data\ClientPermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a client can do with tasks through the assistant. Clients are added to a
 * project as ordinary project members, so every limit has to come from their
 * client role rather than from the project pivot.
 */
final class AssistantClientTaskAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $teammate;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'CIG Florida']);

        $this->teammate = User::factory()->create(['name' => 'Rana Dev']);
        $this->workspace->users()->attach($this->teammate->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($this->teammate->id, ['role' => ProjectRole::MEMBER->value]);
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    private function client(array $permissions = []): User
    {
        $client = User::factory()->create(['name' => 'Casey Client']);

        $roleId = null;

        if ($permissions !== []) {
            $roleId = WorkspaceRole::factory()->create([
                'workspace_id' => $this->workspace->id,
                'permissions' => $permissions,
            ])->id;
        }

        $this->workspace->users()->attach($client->id, [
            'role' => UserRole::CLIENT->value,
            'workspace_role_id' => $roleId,
        ]);

        /* Clients join projects with the ordinary member role — that is the whole point. */
        $this->project->members()->attach($client->id, ['role' => ProjectRole::MEMBER->value]);

        return $client;
    }

    private function context(User $user): ToolContext
    {
        return new ToolContext($user->refresh(), $this->workspace->fresh());
    }

    private function task(string $title = 'Internal work'): Task
    {
        return Task::factory()->create([
            'title' => $title,
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $this->project->boardColumns()->value('id'),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function toolNames(User $user): array
    {
        return array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->context($user)),
        );
    }

    public function test_a_client_whose_role_allows_requests_can_create_a_task(): void
    {
        $client = $this->client([
            ClientPermission::BoardView->value => true,
            ClientPermission::TasksRequest->value => true,
        ]);

        $result = app(CreateTaskTool::class)->execute(['title' => 'Please fix the logo'], $this->context($client));

        $this->assertTrue($result['success']);
        $this->assertSame('CIG Florida', $result['task']['project_name']);
        $this->assertNull($result['task']['assignee_name']);

        $task = Task::query()->where('title', 'Please fix the logo')->firstOrFail();

        /* It lands in the team's triage column, unassigned. */
        $this->assertSame(
            $this->project->boardColumns()->where('is_default', true)->orderBy('position')->value('id'),
            $task->board_column_id,
        );
        $this->assertNull($task->assigned_to);
    }

    public function test_a_read_only_client_is_not_even_offered_task_creation(): void
    {
        $client = $this->client([ClientPermission::BoardView->value => true]);

        $this->assertNotContains('create_task', $this->toolNames($client));

        $result = app(CreateTaskTool::class)->execute(['title' => 'Sneaky'], $this->context($client));

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_client_with_no_custom_role_cannot_create_tasks(): void
    {
        $client = $this->client();

        $this->assertNotContains('create_task', $this->toolNames($client));
        $this->assertFalse($client->can('create', [Task::class, $this->project]));
    }

    public function test_a_client_cannot_hand_the_work_out_to_a_teammate(): void
    {
        $client = $this->client([
            ClientPermission::BoardView->value => true,
            ClientPermission::TasksRequest->value => true,
        ]);

        $result = app(CreateTaskTool::class)->execute([
            'title' => 'Do this, Rana',
            'assignee' => 'Rana',
        ], $this->context($client));

        $this->assertFalse($result['success']);
        $this->assertSame('client_cannot_plan_work', $result['error_code']);
        $this->assertStringContainsString('without an assignee', $result['next_step']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_client_cannot_plan_work_into_a_sprint(): void
    {
        Sprint::factory()->forProject($this->project)->running()->create();

        $client = $this->client([
            ClientPermission::BoardView->value => true,
            ClientPermission::TasksRequest->value => true,
        ]);

        $result = app(CreateTaskTool::class)->execute([
            'title' => 'Urgent request',
            'sprint' => 'current',
        ], $this->context($client));

        $this->assertFalse($result['success']);
        $this->assertSame('client_cannot_plan_work', $result['error_code']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_client_cannot_reassign_an_existing_task(): void
    {
        $client = $this->client([
            ClientPermission::BoardView->value => true,
            ClientPermission::TasksRequest->value => true,
        ]);

        $task = $this->task();

        $result = app(UpdateTaskTool::class)->execute([
            'task_id' => $task->id,
            'assignee' => 'Rana',
        ], $this->context($client));

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertNull($task->fresh()->assigned_to);
    }

    public function test_a_client_with_the_close_permission_can_still_close_a_task(): void
    {
        $client = $this->client([
            ClientPermission::BoardView->value => true,
            ClientPermission::TasksClose->value => true,
        ]);

        $task = $this->task();

        $result = app(UpdateTaskTool::class)->execute([
            'task_id' => $task->id,
            'column' => 'done',
        ], $this->context($client));

        $this->assertTrue($result['success']);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_a_client_without_the_board_permission_cannot_search_tasks(): void
    {
        $client = $this->client([ClientPermission::TasksRequest->value => true]);

        $this->task('Internal secret task');

        $result = app(FindTasksTool::class)->execute(['query' => 'secret'], $this->context($client));

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['tasks']);
        $this->assertSame(0, $result['total_matches']);
        $this->assertStringContainsString('no project here whose tasks you can see', $result['message']);
    }

    public function test_a_client_with_the_board_permission_only_sees_their_own_projects_tasks(): void
    {
        $client = $this->client([ClientPermission::BoardView->value => true]);

        $otherProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Internal Tooling']);
        Task::factory()->create([
            'title' => 'Secret refactor',
            'project_id' => $otherProject->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $otherProject->boardColumns()->value('id'),
        ]);

        $this->task('Logo refresh');

        $result = app(FindTasksTool::class)->execute(['query' => 'refactor'], $this->context($client));

        $this->assertSame(0, $result['total_matches']);
        $this->assertSame(1, app(FindTasksTool::class)->execute(['query' => 'logo'], $this->context($client))['total_matches']);
    }

    public function test_a_client_can_still_raise_a_request_through_the_web_form(): void
    {
        $client = $this->client([
            ClientPermission::BoardView->value => true,
            ClientPermission::TasksRequest->value => true,
        ]);

        $this->actingAs($client)
            ->post(route('workspace.projects.tasks.store', [$this->workspace, $this->project]), [
                'title' => 'Please fix the logo',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('tasks', ['title' => 'Please fix the logo', 'assigned_to' => null]);
    }
}
