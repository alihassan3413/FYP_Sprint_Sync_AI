<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Mail\MeetingCancelledMail;
use App\Mail\MeetingScheduledMail;
use App\Mail\MeetingUpdatedMail;
use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\Notifications\MeetingCancelledNotification;
use App\Notifications\MeetingScheduledNotification;
use App\Notifications\MeetingUpdatedNotification;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class MeetingNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $manager;

    private User $projectMember;

    private User $unassignedMember;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->manager = User::factory()->create();
        $this->workspace->users()->attach($this->manager->id, ['role' => UserRole::MEMBER->value]);

        $this->projectMember = User::factory()->create();
        $this->workspace->users()->attach($this->projectMember->id, ['role' => UserRole::MEMBER->value]);

        $this->unassignedMember = User::factory()->create();
        $this->workspace->users()->attach($this->unassignedMember->id, ['role' => UserRole::MEMBER->value]);

        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
        $this->project->members()->attach($this->manager->id, ['role' => ProjectRole::MANAGER->value]);
        $this->project->members()->attach($this->projectMember->id, ['role' => ProjectRole::MEMBER->value]);
    }

    /**
     * @return array<int, int>
     */
    private function participantIds(): array
    {
        return [$this->manager->id, $this->projectMember->id];
    }

    private function meetingRoute(string $name, ?Meeting $meeting = null, array $extra = []): string
    {
        $params = array_merge(['workspace' => $this->workspace, 'project' => $this->project], $extra);

        if ($meeting !== null) {
            $params['meeting'] = $meeting;
        }

        return route($name, $params);
    }

    public function test_scheduling_a_meeting_emails_the_project_members(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Sprint planning',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_user_ids' => $this->participantIds(),
            ])
            ->assertRedirect();

        Mail::assertQueued(MeetingScheduledMail::class, 2);
        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => $mail->hasTo($this->manager->email));
        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => $mail->hasTo($this->projectMember->email));
        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => ! $mail->hasTo($this->unassignedMember->email));
    }

    public function test_scheduling_a_meeting_includes_the_expected_details_in_the_email(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Design review',
                'description' => 'Walk through the new mockups.',
                'scheduled_at' => '2026-09-05 14:00:00',
                'duration_minutes' => 45,
                'meeting_link' => 'https://meet.example.com/design-review',
                'participant_user_ids' => $this->participantIds(),
            ])
            ->assertRedirect();

        $meeting = Meeting::query()->where('title', 'Design review')->firstOrFail();

        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => $mail->projectName === $this->project->name
            && $mail->meetingTitle === 'Design review'
            && $mail->agenda === 'Walk through the new mockups.'
            && $mail->durationMinutes === 45
            && $mail->joinUrl === route('meetings.join', ['token' => $meeting->join_token])
            && $mail->scheduledByName === $this->owner->name);

        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => $mail->joinUrl !== 'https://meet.example.com/design-review');
    }

    public function test_the_meeting_creator_is_not_emailed_their_own_meeting(): void
    {
        $this->actingAs($this->manager)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Manager scheduled',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_user_ids' => $this->participantIds(),
            ])
            ->assertRedirect();

        Mail::assertQueued(MeetingScheduledMail::class, 1);
        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => $mail->hasTo($this->projectMember->email));
        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => ! $mail->hasTo($this->manager->email));
    }

    public function test_updating_meaningful_meeting_details_emails_the_project_members(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->withParticipants($this->manager, $this->projectMember)->create([
            'title' => 'Old title',
            'description' => null,
            'scheduled_at' => '2026-09-01 10:00:00',
            'duration_minutes' => 30,
            'meeting_link' => null,
        ]);

        $this->actingAs($this->owner)
            ->put($this->meetingRoute('workspace.projects.meetings.update', $meeting), [
                'title' => 'New title',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_user_ids' => $this->participantIds(),
            ])
            ->assertRedirect();

        Mail::assertQueued(MeetingUpdatedMail::class, 2);
        Mail::assertQueued(MeetingUpdatedMail::class, fn ($mail) => $mail->meetingTitle === 'New title');
    }

    public function test_updating_a_meeting_without_meaningful_changes_does_not_email(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->withParticipants($this->manager, $this->projectMember)->create([
            'title' => 'Stable title',
            'description' => null,
            'scheduled_at' => '2026-09-01 10:00:00',
            'duration_minutes' => 30,
            'meeting_link' => null,
        ]);

        $this->actingAs($this->owner)
            ->put($this->meetingRoute('workspace.projects.meetings.update', $meeting), [
                'title' => 'Stable title',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_user_ids' => $this->participantIds(),
            ])
            ->assertRedirect();

        Mail::assertNotQueued(MeetingUpdatedMail::class);
    }

    public function test_the_meeting_editor_is_not_emailed_their_own_update(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->withParticipants($this->manager, $this->projectMember)->create([
            'title' => 'Old title',
            'description' => null,
            'meeting_link' => null,
        ]);

        $this->actingAs($this->manager)
            ->put($this->meetingRoute('workspace.projects.meetings.update', $meeting), [
                'title' => 'New title',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_user_ids' => $this->participantIds(),
            ])
            ->assertRedirect();

        Mail::assertQueued(MeetingUpdatedMail::class, 1);
        Mail::assertQueued(MeetingUpdatedMail::class, fn ($mail) => $mail->hasTo($this->projectMember->email));
        Mail::assertQueued(MeetingUpdatedMail::class, fn ($mail) => ! $mail->hasTo($this->manager->email));
    }

    public function test_deleting_a_meeting_emails_a_cancellation_to_the_project_members(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->withParticipants($this->manager, $this->projectMember)->create(['title' => 'Retro']);

        $this->actingAs($this->owner)
            ->delete($this->meetingRoute('workspace.projects.meetings.destroy', $meeting))
            ->assertRedirect();

        Mail::assertQueued(MeetingCancelledMail::class, 2);
        Mail::assertQueued(MeetingCancelledMail::class, fn ($mail) => $mail->meetingTitle === 'Retro' && $mail->cancelledByName === $this->owner->name);
    }

    public function test_the_meeting_deleter_is_not_emailed_their_own_cancellation(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->withParticipants($this->manager, $this->projectMember)->create();

        $this->actingAs($this->manager)
            ->delete($this->meetingRoute('workspace.projects.meetings.destroy', $meeting))
            ->assertRedirect();

        Mail::assertQueued(MeetingCancelledMail::class, 1);
        Mail::assertQueued(MeetingCancelledMail::class, fn ($mail) => $mail->hasTo($this->projectMember->email));
        Mail::assertQueued(MeetingCancelledMail::class, fn ($mail) => ! $mail->hasTo($this->manager->email));
    }

    public function test_unassigned_workspace_members_are_never_notified(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->withParticipants($this->manager, $this->projectMember)->create([
            'description' => null,
            'meeting_link' => null,
        ]);

        $this->actingAs($this->owner)
            ->put($this->meetingRoute('workspace.projects.meetings.update', $meeting), [
                'title' => 'Rescheduled',
                'scheduled_at' => '2026-10-01 10:00:00',
                'duration_minutes' => 60,
                'participant_user_ids' => $this->participantIds(),
            ]);

        $this->actingAs($this->owner)
            ->delete($this->meetingRoute('workspace.projects.meetings.destroy', $meeting));

        Mail::assertNotQueued(MeetingScheduledMail::class, fn ($mail) => $mail->hasTo($this->unassignedMember->email));
        Mail::assertNotQueued(MeetingUpdatedMail::class, fn ($mail) => $mail->hasTo($this->unassignedMember->email));
        Mail::assertNotQueued(MeetingCancelledMail::class, fn ($mail) => $mail->hasTo($this->unassignedMember->email));
    }

    public function test_no_duplicate_recipients_for_a_meeting_notification(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Dedup check',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_user_ids' => $this->participantIds(),
            ])
            ->assertRedirect();

        Mail::assertQueued(MeetingScheduledMail::class, 2);

        $recipients = Mail::queued(MeetingScheduledMail::class)
            ->flatMap(fn ($mail) => collect($mail->to)->pluck('address'));

        $this->assertSame($recipients->count(), $recipients->unique()->count());
    }

    public function test_scheduling_a_meeting_creates_in_app_notifications_for_the_project_members(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Sprint planning',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_user_ids' => $this->participantIds(),
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->manager, MeetingScheduledNotification::class);
        Notification::assertSentTo($this->projectMember, MeetingScheduledNotification::class);
        Notification::assertNotSentTo($this->unassignedMember, MeetingScheduledNotification::class);
        Notification::assertNotSentTo($this->owner, MeetingScheduledNotification::class);
    }

    public function test_updating_a_meeting_creates_in_app_notifications(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->withParticipants($this->manager, $this->projectMember)->create([
            'title' => 'Old title',
            'description' => null,
            'meeting_link' => null,
        ]);

        $this->actingAs($this->owner)
            ->put($this->meetingRoute('workspace.projects.meetings.update', $meeting), [
                'title' => 'New title',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_user_ids' => $this->participantIds(),
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->projectMember,
            MeetingUpdatedNotification::class,
            fn ($notification) => str_contains($notification->toArray($this->projectMember)['message'], 'New title'),
        );
    }

    public function test_cancelling_a_meeting_creates_in_app_notifications(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->withParticipants($this->manager, $this->projectMember)->create(['title' => 'Retro']);

        $this->actingAs($this->owner)
            ->delete($this->meetingRoute('workspace.projects.meetings.destroy', $meeting))
            ->assertRedirect();

        Notification::assertSentTo($this->manager, MeetingCancelledNotification::class);
        Notification::assertSentTo($this->projectMember, MeetingCancelledNotification::class);
        Notification::assertNotSentTo($this->owner, MeetingCancelledNotification::class);
    }

    public function test_meeting_notification_link_points_to_the_project(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Sprint planning',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_user_ids' => $this->participantIds(),
            ])
            ->assertRedirect();

        $expectedUrl = route('workspace.projects.show', ['workspace' => $this->workspace, 'project' => $this->project]);

        Notification::assertSentTo(
            $this->manager,
            MeetingScheduledNotification::class,
            fn ($notification) => $notification->toArray($this->manager)['url'] === $expectedUrl,
        );
    }
}
