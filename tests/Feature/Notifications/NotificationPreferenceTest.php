<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Mail\MeetingScheduledMail;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\Notifications\MeetingScheduledNotification;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationType;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskMovedNotification;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $assignee;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->assignee = User::factory()->create();
        $this->workspace->users()->attach($this->assignee->id, ['role' => UserRole::MEMBER->value]);

        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
        $this->project->members()->attach($this->assignee->id, ['role' => ProjectRole::MEMBER->value]);
    }

    /**
     * @return array<int, array{type: string, channel: string, enabled: bool}>
     */
    private function allEnabledPayload(): array
    {
        return collect(NotificationType::values())
            ->flatMap(fn (NotificationType $type) => collect($type->channels())->map(fn (NotificationChannel $channel) => [
                'type' => $type->value,
                'channel' => $channel->value,
                'enabled' => true,
            ]))
            ->values()
            ->all();
    }

    public function test_defaults_are_enabled_for_all_currently_supported_types_and_channels(): void
    {
        $this->actingAs($this->assignee)
            ->get(route('notification-preferences.edit'))
            ->assertInertia(function ($page) {
                $page->has('groups', 2);

                foreach ($page->toArray()['props']['groups'] as $group) {
                    foreach ($group['items'] as $item) {
                        foreach ($item['channels'] as $channel) {
                            $this->assertTrue($channel['enabled']);
                        }
                    }
                }
            });
    }

    public function test_user_can_disable_an_in_app_notification_type(): void
    {
        $payload = $this->allEnabledPayload();

        foreach ($payload as $index => $entry) {
            if ($entry['type'] === NotificationType::TASK_ASSIGNED->value && $entry['channel'] === NotificationChannel::IN_APP->value) {
                $payload[$index]['enabled'] = false;
            }
        }

        $this->actingAs($this->assignee)
            ->put(route('notification-preferences.update'), ['preferences' => $payload])
            ->assertRedirect(route('notification-preferences.edit'));

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->assignee->id,
            'type' => NotificationType::TASK_ASSIGNED->value,
            'channel' => NotificationChannel::IN_APP->value,
            'enabled' => false,
        ]);
    }

    public function test_disabled_task_assigned_notification_is_not_dispatched_or_stored(): void
    {
        NotificationPreference::factory()
            ->forUser($this->assignee)
            ->type(NotificationType::TASK_ASSIGNED)
            ->channel(NotificationChannel::IN_APP)
            ->disabled()
            ->create();

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.tasks.store', ['workspace' => $this->workspace, 'project' => $this->project]), [
                'title' => 'Write onboarding docs',
                'assigned_to' => $this->assignee->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, $this->assignee->notifications()->count());
    }

    public function test_another_enabled_notification_type_still_works_when_a_different_type_is_disabled(): void
    {
        NotificationPreference::factory()
            ->forUser($this->assignee)
            ->type(NotificationType::TASK_ASSIGNED)
            ->channel(NotificationChannel::IN_APP)
            ->disabled()
            ->create();

        Notification::fake();

        $task = Task::factory()->forProject($this->project)->assignedTo($this->assignee)->create();
        $inProgress = $this->project->boardColumns()->where('name', 'In Progress')->firstOrFail();

        $this->actingAs($this->owner)
            ->patch(route('workspace.projects.tasks.update-status', ['workspace' => $this->workspace, 'project' => $this->project, 'task' => $task]), [
                'board_column_id' => $inProgress->id,
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->assignee, TaskMovedNotification::class);
        Notification::assertNotSentTo($this->assignee, TaskAssignedNotification::class);
    }

    public function test_meeting_email_can_be_disabled_independently_from_in_app_notification(): void
    {
        NotificationPreference::factory()
            ->forUser($this->assignee)
            ->type(NotificationType::MEETING_SCHEDULED)
            ->channel(NotificationChannel::EMAIL)
            ->disabled()
            ->create();

        Mail::fake();
        Notification::fake();

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.meetings.store', ['workspace' => $this->workspace, 'project' => $this->project]), [
                'title' => 'Sprint planning',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_user_ids' => [$this->assignee->id],
            ])
            ->assertRedirect();

        Mail::assertNotQueued(MeetingScheduledMail::class, fn ($mail) => $mail->hasTo($this->assignee->email));
        Notification::assertSentTo($this->assignee, MeetingScheduledNotification::class);
    }

    public function test_disabling_in_app_meeting_notification_does_not_affect_the_email(): void
    {
        NotificationPreference::factory()
            ->forUser($this->assignee)
            ->type(NotificationType::MEETING_SCHEDULED)
            ->channel(NotificationChannel::IN_APP)
            ->disabled()
            ->create();

        Mail::fake();
        Notification::fake();

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.meetings.store', ['workspace' => $this->workspace, 'project' => $this->project]), [
                'title' => 'Sprint planning',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_user_ids' => [$this->assignee->id],
            ])
            ->assertRedirect();

        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => $mail->hasTo($this->assignee->email));
        Notification::assertNotSentTo($this->assignee, MeetingScheduledNotification::class);
    }

    public function test_preferences_only_affect_the_user_who_set_them(): void
    {
        $secondAssignee = User::factory()->create();
        $this->workspace->users()->attach($secondAssignee->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($secondAssignee->id, ['role' => ProjectRole::MEMBER->value]);

        NotificationPreference::factory()
            ->forUser($this->assignee)
            ->type(NotificationType::TASK_ASSIGNED)
            ->channel(NotificationChannel::IN_APP)
            ->disabled()
            ->create();

        Notification::fake();

        $task = Task::factory()->forProject($this->project)->create();

        $this->actingAs($this->owner)
            ->put(route('workspace.projects.tasks.update', ['workspace' => $this->workspace, 'project' => $this->project, 'task' => $task]), [
                'title' => $task->title,
                'assigned_to' => $this->assignee->id,
            ])
            ->assertRedirect();

        Notification::assertNotSentTo($this->assignee, TaskAssignedNotification::class);

        $task2 = Task::factory()->forProject($this->project)->create();

        $this->actingAs($this->owner)
            ->put(route('workspace.projects.tasks.update', ['workspace' => $this->workspace, 'project' => $this->project, 'task' => $task2]), [
                'title' => $task2->title,
                'assigned_to' => $secondAssignee->id,
            ])
            ->assertRedirect();

        Notification::assertSentTo($secondAssignee, TaskAssignedNotification::class);
    }

    public function test_a_user_cannot_modify_another_users_notification_preferences(): void
    {
        $otherUser = User::factory()->create();

        $payload = $this->allEnabledPayload();
        foreach ($payload as $index => $entry) {
            if ($entry['type'] === NotificationType::TASK_ASSIGNED->value && $entry['channel'] === NotificationChannel::IN_APP->value) {
                $payload[$index]['enabled'] = false;
            }
        }

        $this->actingAs($this->assignee)
            ->put(route('notification-preferences.update'), ['preferences' => $payload])
            ->assertRedirect();

        $this->assertDatabaseMissing('notification_preferences', [
            'user_id' => $otherUser->id,
        ]);

        $this->actingAs($otherUser)
            ->get(route('notification-preferences.edit'))
            ->assertInertia(function ($page) {
                foreach ($page->toArray()['props']['groups'] as $group) {
                    foreach ($group['items'] as $item) {
                        foreach ($item['channels'] as $channel) {
                            $this->assertTrue($channel['enabled']);
                        }
                    }
                }
            });
    }

    public function test_guests_cannot_view_or_update_notification_preferences(): void
    {
        $this->get(route('notification-preferences.edit'))->assertRedirect(route('login'));
        $this->put(route('notification-preferences.update'), ['preferences' => $this->allEnabledPayload()])
            ->assertRedirect(route('login'));
    }

    public function test_update_rejects_a_channel_that_the_notification_type_does_not_support(): void
    {
        $this->actingAs($this->assignee)
            ->put(route('notification-preferences.update'), [
                'preferences' => [
                    ['type' => NotificationType::TASK_ASSIGNED->value, 'channel' => NotificationChannel::EMAIL->value, 'enabled' => true],
                ],
            ])
            ->assertSessionHasErrors('preferences.0.channel');

        $this->assertDatabaseCount('notification_preferences', 0);
    }
}
