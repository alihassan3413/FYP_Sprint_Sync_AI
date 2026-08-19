<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Events\TaskStatusUpdated;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class TaskStatusBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $assignee;

    private Project $project;

    private BoardColumn $todo;

    private BoardColumn $done;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->todo = BoardColumn::query()->where('project_id', $this->project->id)->orderBy('position')->firstOrFail();
        $this->done = BoardColumn::query()->where('project_id', $this->project->id)->where('name', 'Done')->firstOrFail();

        $this->assignee = User::factory()->create();
        $this->workspace->users()->attach($this->assignee->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($this->assignee->id, ['role' => ProjectRole::MEMBER->value]);

        $this->task = Task::factory()
            ->forProject($this->project)
            ->forColumn($this->todo)
            ->assignedTo($this->assignee)
            ->create();
    }

    private function moveRoute(): string
    {
        return route('workspace.projects.tasks.update-status', [
            'workspace' => $this->workspace->slug,
            'project' => $this->project->id,
            'task' => $this->task->id,
        ]);
    }

    public function test_a_status_change_broadcasts_the_event(): void
    {
        Event::fake([TaskStatusUpdated::class]);

        $this->actingAs($this->assignee)
            ->patch($this->moveRoute(), ['board_column_id' => $this->done->id])
            ->assertRedirect();

        Event::assertDispatched(
            TaskStatusUpdated::class,
            fn (TaskStatusUpdated $event) => $event->taskId === $this->task->id
                && $event->projectId === $this->project->id
                && $event->boardColumnId === $this->done->id,
        );
    }

    public function test_a_no_op_status_change_broadcasts_nothing(): void
    {
        Event::fake([TaskStatusUpdated::class]);

        $this->actingAs($this->assignee)
            ->patch($this->moveRoute(), ['board_column_id' => $this->todo->id])
            ->assertRedirect();

        Event::assertNotDispatched(TaskStatusUpdated::class);
    }

    public function test_an_unauthorized_move_broadcasts_nothing(): void
    {
        Event::fake([TaskStatusUpdated::class]);

        $outsider = User::factory()->create();
        $this->workspace->users()->attach($outsider->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($outsider)
            ->patch($this->moveRoute(), ['board_column_id' => $this->done->id])
            ->assertForbidden();

        Event::assertNotDispatched(TaskStatusUpdated::class);
    }

    public function test_the_event_broadcasts_on_the_private_project_channel(): void
    {
        $event = TaskStatusUpdated::fromTask($this->task->refresh());

        $channel = $event->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame("private-project.{$this->project->id}", (string) $channel);
        $this->assertSame('task.status-updated', $event->broadcastAs());
    }

    public function test_the_payload_contains_only_the_intended_fields(): void
    {
        $payload = TaskStatusUpdated::fromTask($this->task->refresh())->broadcastWith();

        $this->assertSame(
            ['task_id', 'project_id', 'board_column_id', 'updated_at'],
            array_keys($payload),
        );

        $this->assertSame($this->task->id, $payload['task_id']);
        $this->assertSame($this->project->id, $payload['project_id']);
        $this->assertSame($this->todo->id, $payload['board_column_id']);

        $encoded = json_encode($payload);

        $this->assertStringNotContainsString($this->task->title, $encoded);
        $this->assertStringNotContainsString((string) $this->assignee->email, $encoded);
    }

    public function test_moving_a_task_still_persists_notifies_and_audits(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->moveRoute(), ['board_column_id' => $this->done->id])
            ->assertRedirect();

        $this->assertSame($this->done->id, $this->task->refresh()->board_column_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.moved']);
    }
}
