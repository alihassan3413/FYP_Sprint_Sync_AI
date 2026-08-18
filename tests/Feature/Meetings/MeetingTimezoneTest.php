<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Mail\MeetingScheduledMail;
use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class MeetingTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->owner = User::factory()->create(['timezone' => 'Asia/Karachi']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
    }

    private function meetingRoute(string $name, ?Meeting $meeting = null): string
    {
        $params = ['workspace' => $this->workspace, 'project' => $this->project];

        if ($meeting !== null) {
            $params['meeting'] = $meeting;
        }

        return route($name, $params);
    }

    public function test_a_scheduled_time_is_stored_as_utc_from_the_organisers_zone(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Sprint planning',
                'scheduled_at' => '2026-09-01 15:00',
                'duration_minutes' => 30,
            ])
            ->assertRedirect();

        $this->assertSame('2026-09-01 10:00:00', Meeting::query()->firstOrFail()->scheduled_at->toDateTimeString());
    }

    public function test_a_user_without_a_timezone_falls_back_to_the_application_timezone(): void
    {
        $organiser = User::factory()->create(['timezone' => null]);
        $this->workspace->users()->attach($organiser->id, ['role' => UserRole::ADMIN->value]);

        $this->actingAs($organiser)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Standup',
                'scheduled_at' => '2026-09-01 15:00',
                'duration_minutes' => 15,
            ])
            ->assertRedirect();

        $expected = now()->parse('2026-09-01 15:00', config('app.timezone'))->utc()->toDateTimeString();

        $this->assertSame($expected, Meeting::query()->firstOrFail()->scheduled_at->toDateTimeString());
    }

    public function test_an_update_reinterprets_the_time_in_the_editors_zone(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Sprint planning',
                'scheduled_at' => '2026-09-01 15:00',
                'duration_minutes' => 30,
            ])
            ->assertRedirect();

        $meeting = Meeting::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->put($this->meetingRoute('workspace.projects.meetings.update', $meeting), [
                'title' => 'Sprint planning',
                'scheduled_at' => '2026-09-01 18:30',
                'duration_minutes' => 30,
            ])
            ->assertRedirect();

        $this->assertSame('2026-09-01 13:30:00', $meeting->refresh()->scheduled_at->toDateTimeString());
    }

    public function test_each_internal_recipient_is_emailed_the_time_in_their_own_zone(): void
    {
        $karachi = User::factory()->create(['timezone' => 'Asia/Karachi']);
        $newYork = User::factory()->create(['timezone' => 'America/New_York']);

        foreach ([$karachi, $newYork] as $participant) {
            $this->workspace->users()->attach($participant->id, ['role' => UserRole::MEMBER->value]);
            $this->project->members()->attach($participant->id, ['role' => ProjectRole::MEMBER->value]);
        }

        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Sprint planning',
                'scheduled_at' => '2026-09-01 15:00',
                'duration_minutes' => 30,
                'participant_user_ids' => [$karachi->id, $newYork->id],
            ])
            ->assertRedirect();

        Mail::assertQueued(
            MeetingScheduledMail::class,
            fn (MeetingScheduledMail $mail) => $mail->hasTo($karachi->email)
                && $mail->scheduledAt === 'September 1, 2026 3:00 PM (PKT)',
        );

        Mail::assertQueued(
            MeetingScheduledMail::class,
            fn (MeetingScheduledMail $mail) => $mail->hasTo($newYork->email)
                && $mail->scheduledAt === 'September 1, 2026 6:00 AM (EDT)',
        );
    }

    public function test_an_external_participant_is_emailed_the_time_in_the_organisers_zone(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Sprint planning',
                'scheduled_at' => '2026-09-01 15:00',
                'duration_minutes' => 30,
                'participant_emails' => ['outsider@example.com'],
            ])
            ->assertRedirect();

        Mail::assertQueued(
            MeetingScheduledMail::class,
            fn (MeetingScheduledMail $mail) => $mail->hasTo('outsider@example.com')
                && $mail->scheduledAt === 'September 1, 2026 3:00 PM (PKT)',
        );
    }
}
