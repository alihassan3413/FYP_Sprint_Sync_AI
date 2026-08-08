<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCommentPostedNotification;
use App\Notifications\TaskMovedNotification;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class TaskNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $manager;

    private User $assignee;

    private User $unrelatedMember;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->manager = User::factory()->create();
        $this->workspace->users()->attach($this->manager->id, ['role' => UserRole::MEMBER->value]);

        $this->assignee = User::factory()->create();
        $this->workspace->users()->attach($this->assignee->id, ['role' => UserRole::MEMBER->value]);

        $this->unrelatedMember = User::factory()->create();
        $this->workspace->users()->attach($this->unrelatedMember->id, ['role' => UserRole::MEMBER->value]);

        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
        $this->project->members()->attach($this->manager->id, ['role' => ProjectRole::MANAGER->value]);
        $this->project->members()->attach($this->assignee->id, ['role' => ProjectRole::MEMBER->value]);
    }

    private function taskRoute(string $name, ?Task $task = null, array $extra = []): string
    {
        $params = array_merge(['workspace' => $this->workspace, 'project' => $this->project], $extra);

        if ($task !== null) {
            $params['task'] = $task;
        }

        return route($name, $params);
    }

    public function test_creating_a_task_with_an_assignee_notifies_the_assignee(): void
    {
        $this->actingAs($this->owner)
            ->post($this->taskRoute('workspace.projects.tasks.store'), [
                'title' => 'Write onboarding docs',
                'assigned_to' => $this->assignee->id,
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->assignee, TaskAssignedNotification::class);
        Notification::assertNotSentTo($this->owner, TaskAssignedNotification::class);
    }

    public function test_creating_a_task_without_an_assignee_notifies_nobody(): void
    {
        $this->actingAs($this->owner)
            ->post($this->taskRoute('workspace.projects.tasks.store'), [
                'title' => 'Unassigned task',
            ])
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_assigning_a_task_to_yourself_does_not_notify_yourself(): void
    {
        $this->actingAs($this->manager)
            ->post($this->taskRoute('workspace.projects.tasks.store'), [
                'title' => 'Self-assigned task',
                'assigned_to' => $this->manager->id,
            ])
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_updating_a_tasks_assignee_notifies_the_new_assignee(): void
    {
        $task = Task::factory()->forProject($this->project)->create(['title' => 'Old title']);

        $this->actingAs($this->manager)
            ->put($this->taskRoute('workspace.projects.tasks.update', $task), [
                'title' => 'Old title',
                'assigned_to' => $this->assignee->id,
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->assignee, TaskAssignedNotification::class);
    }

    public function test_updating_a_task_without_changing_the_assignee_does_not_notify(): void
    {
        $task = Task::factory()->forProject($this->project)->assignedTo($this->assignee)->create(['title' => 'Old title']);

        $this->actingAs($this->manager)
            ->put($this->taskRoute('workspace.projects.tasks.update', $task), [
                'title' => 'New title',
                'assigned_to' => $this->assignee->id,
            ])
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_moving_a_task_to_another_column_notifies_the_assignee(): void
    {
        $task = Task::factory()->forProject($this->project)->assignedTo($this->assignee)->create();
        $inProgress = BoardColumn::query()->where('project_id', $this->project->id)->where('name', 'In Progress')->firstOrFail();

        $this->actingAs($this->manager)
            ->patch($this->taskRoute('workspace.projects.tasks.update-status', $task), [
                'board_column_id' => $inProgress->id,
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->assignee,
            TaskMovedNotification::class,
            fn ($notification) => str_contains($notification->toArray($this->assignee)['message'], 'In Progress'),
        );
    }

    public function test_the_assignee_moving_their_own_task_is_not_notified(): void
    {
        $task = Task::factory()->forProject($this->project)->assignedTo($this->assignee)->create();
        $inProgress = BoardColumn::query()->where('project_id', $this->project->id)->where('name', 'In Progress')->firstOrFail();

        $this->actingAs($this->assignee)
            ->patch($this->taskRoute('workspace.projects.tasks.update-status', $task), [
                'board_column_id' => $inProgress->id,
            ])
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_commenting_on_a_task_notifies_the_assignee(): void
    {
        $task = Task::factory()->forProject($this->project)->assignedTo($this->assignee)->create(['title' => 'Ship the release']);

        $this->actingAs($this->owner)
            ->post($this->taskRoute('workspace.projects.tasks.comments.store', $task), ['body' => 'Great work on this.'])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->assignee,
            TaskCommentPostedNotification::class,
            fn ($notification) => str_contains($notification->toArray($this->assignee)['message'], 'Great work on this.'),
        );
    }

    public function test_the_comment_author_is_not_notified_of_their_own_comment(): void
    {
        $task = Task::factory()->forProject($this->project)->assignedTo($this->assignee)->create();

        $this->actingAs($this->assignee)
            ->post($this->taskRoute('workspace.projects.tasks.comments.store', $task), ['body' => 'Working on it.'])
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_unrelated_project_members_are_never_notified_of_task_activity(): void
    {
        $task = Task::factory()->forProject($this->project)->assignedTo($this->assignee)->create();

        $this->actingAs($this->owner)
            ->post($this->taskRoute('workspace.projects.tasks.comments.store', $task), ['body' => 'Status update please.'])
            ->assertRedirect();

        Notification::assertNotSentTo($this->unrelatedMember, TaskCommentPostedNotification::class);
        Notification::assertNotSentTo($this->manager, TaskCommentPostedNotification::class);
    }

    public function test_task_assignment_notification_links_to_the_project(): void
    {
        $this->actingAs($this->owner)
            ->post($this->taskRoute('workspace.projects.tasks.store'), [
                'title' => 'Write onboarding docs',
                'assigned_to' => $this->assignee->id,
            ])
            ->assertRedirect();

        $expectedUrl = route('workspace.projects.show', ['workspace' => $this->workspace, 'project' => $this->project]);

        Notification::assertSentTo(
            $this->assignee,
            TaskAssignedNotification::class,
            fn ($notification) => $notification->toArray($this->assignee)['url'] === $expectedUrl,
        );
    }
}
