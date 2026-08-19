<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Modules\Meetings\Actions\CreateMeetingAction;
use App\Modules\Meetings\Actions\DeleteMeetingAction;
use App\Modules\Meetings\Actions\UpdateMeetingAction;
use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\Notifications\MeetingCancelledNotification;
use App\Notifications\MeetingScheduledNotification;
use App\Notifications\MeetingUpdatedNotification;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationType;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class MeetingNotificationTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $organiser;

    private User $karachi;

    private User $newYork;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->organiser = User::factory()->create(['timezone' => 'Europe/London']);
        $this->workspace = Workspace::factory()->ownedBy($this->organiser)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mobile App']);

        $this->karachi = $this->projectMember('Asia/Karachi');
        $this->newYork = $this->projectMember('America/New_York');
    }

    private function projectMember(?string $timezone): User
    {
        $user = User::factory()->create(['timezone' => $timezone]);
        $this->workspace->users()->attach($user->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($user->id, ['role' => ProjectRole::MEMBER->value]);

        return $user;
    }

    private function scheduleMeeting(array $emails = []): Meeting
    {
        return app(CreateMeetingAction::class)->handle($this->project, $this->organiser, StoreMeetingData::from([
            'title' => 'Sprint review',
            'description' => null,
            'scheduled_at' => '2026-09-01 10:00:00',
            'duration_minutes' => 45,
            'meeting_link' => null,
            'participant_user_ids' => [],
            'participant_emails' => array_merge([$this->karachi->email, $this->newYork->email], $emails),
        ]));
    }

    private function messageFor(BaseNotification $notification, User $notifiable): string
    {
        return $notification->toArray($notifiable)['message'];
    }

    public function test_a_scheduled_meeting_reads_in_each_recipients_own_timezone(): void
    {
        $this->scheduleMeeting();

        Notification::assertSentTo(
            $this->karachi,
            MeetingScheduledNotification::class,
            fn (MeetingScheduledNotification $notification) => str_contains(
                $this->messageFor($notification, $this->karachi),
                'September 1, 2026 3:00 PM (PKT)',
            ),
        );

        Notification::assertSentTo(
            $this->newYork,
            MeetingScheduledNotification::class,
            fn (MeetingScheduledNotification $notification) => str_contains(
                $this->messageFor($notification, $this->newYork),
                'September 1, 2026 6:00 AM (EDT)',
            ),
        );
    }

    public function test_one_scheduled_notification_renders_differently_for_two_recipients(): void
    {
        $this->scheduleMeeting();

        $captured = null;

        Notification::assertSentTo(
            $this->karachi,
            MeetingScheduledNotification::class,
            function (MeetingScheduledNotification $notification) use (&$captured) {
                $captured = $notification;

                return true;
            },
        );

        $this->assertNotNull($captured);
        $this->assertStringContainsString('3:00 PM (PKT)', $this->messageFor($captured, $this->karachi));
        $this->assertStringContainsString('6:00 AM (EDT)', $this->messageFor($captured, $this->newYork));
    }

    public function test_an_updated_meeting_reads_in_each_recipients_own_timezone(): void
    {
        $meeting = $this->scheduleMeeting();

        app(UpdateMeetingAction::class)->handle($meeting, $this->organiser, StoreMeetingData::from([
            'title' => 'Sprint review',
            'description' => null,
            'scheduled_at' => '2026-09-02 13:30:00',
            'duration_minutes' => 45,
            'meeting_link' => null,
            'participant_user_ids' => [],
            'participant_emails' => [$this->karachi->email, $this->newYork->email],
        ]));

        Notification::assertSentTo(
            $this->karachi,
            MeetingUpdatedNotification::class,
            fn (MeetingUpdatedNotification $notification) => str_contains(
                $this->messageFor($notification, $this->karachi),
                'September 2, 2026 6:30 PM (PKT)',
            ),
        );

        Notification::assertSentTo(
            $this->newYork,
            MeetingUpdatedNotification::class,
            fn (MeetingUpdatedNotification $notification) => str_contains(
                $this->messageFor($notification, $this->newYork),
                'September 2, 2026 9:30 AM (EDT)',
            ),
        );
    }

    public function test_a_cancelled_meeting_reads_in_each_recipients_own_timezone(): void
    {
        $meeting = $this->scheduleMeeting();

        app(DeleteMeetingAction::class)->handle($meeting, $this->organiser);

        Notification::assertSentTo(
            $this->karachi,
            MeetingCancelledNotification::class,
            fn (MeetingCancelledNotification $notification) => str_contains(
                $this->messageFor($notification, $this->karachi),
                'September 1, 2026 3:00 PM (PKT)',
            ),
        );

        Notification::assertSentTo(
            $this->newYork,
            MeetingCancelledNotification::class,
            fn (MeetingCancelledNotification $notification) => str_contains(
                $this->messageFor($notification, $this->newYork),
                'September 1, 2026 6:00 AM (EDT)',
            ),
        );
    }

    public function test_a_recipient_without_a_timezone_falls_back_to_the_application_timezone(): void
    {
        $unset = $this->projectMember(null);

        $this->scheduleMeeting([$unset->email]);

        Notification::assertSentTo(
            $unset,
            MeetingScheduledNotification::class,
            fn (MeetingScheduledNotification $notification) => str_contains(
                $this->messageFor($notification, $unset),
                'September 1, 2026 10:00 AM (UTC)',
            ),
        );
    }

    public function test_the_notification_payload_keeps_its_type_title_and_url(): void
    {
        $this->scheduleMeeting();

        $expectedUrl = route('workspace.projects.show', [
            'workspace' => $this->workspace->slug,
            'project' => $this->project->id,
        ]);

        Notification::assertSentTo(
            $this->karachi,
            MeetingScheduledNotification::class,
            function (MeetingScheduledNotification $notification) use ($expectedUrl) {
                $payload = $notification->toArray($this->karachi);

                return $payload['type'] === 'meeting_scheduled'
                    && $payload['title'] === 'New meeting scheduled'
                    && $payload['url'] === $expectedUrl;
            },
        );
    }

    public function test_the_actor_is_still_excluded_from_in_app_notifications(): void
    {
        $this->scheduleMeeting();

        Notification::assertNotSentTo($this->organiser, MeetingScheduledNotification::class);
    }

    public function test_an_external_participant_receives_no_in_app_notification(): void
    {
        $meeting = $this->scheduleMeeting(['outsider@example.com']);

        $external = $meeting->participants()->whereNull('user_id')->firstOrFail();

        $this->assertSame('outsider@example.com', $external->email);
        $this->assertTrue($external->isExternal());

        Notification::assertCount(2);
    }

    public function test_an_in_app_preference_opt_out_is_still_respected(): void
    {
        NotificationPreference::query()->create([
            'user_id' => $this->newYork->id,
            'type' => NotificationType::MEETING_SCHEDULED->value,
            'channel' => NotificationChannel::IN_APP->value,
            'enabled' => false,
        ]);

        $this->scheduleMeeting();

        Notification::assertSentTo($this->karachi, MeetingScheduledNotification::class);
        Notification::assertNotSentTo($this->newYork, MeetingScheduledNotification::class);
    }

    public function test_a_member_of_another_workspace_receives_nothing(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $stranger = User::factory()->create(['timezone' => 'Asia/Tokyo']);
        $otherWorkspace->users()->attach($stranger->id, ['role' => UserRole::MEMBER->value]);

        $this->scheduleMeeting();

        Notification::assertNotSentTo($stranger, MeetingScheduledNotification::class);
    }
}
