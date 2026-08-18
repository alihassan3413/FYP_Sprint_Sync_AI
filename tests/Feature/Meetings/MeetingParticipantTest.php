<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Mail\MeetingCancelledMail;
use App\Mail\MeetingScheduledMail;
use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingParticipant;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MeetingParticipantTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $projectMember;

    private User $unassignedMember;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->projectMember = User::factory()->create();
        $this->workspace->users()->attach($this->projectMember->id, ['role' => UserRole::MEMBER->value]);

        $this->unassignedMember = User::factory()->create();
        $this->workspace->users()->attach($this->unassignedMember->id, ['role' => UserRole::MEMBER->value]);

        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
        $this->project->members()->attach($this->projectMember->id, ['role' => ProjectRole::MEMBER->value]);
    }

    private function route(string $name, ?Meeting $meeting = null): string
    {
        $params = ['workspace' => $this->workspace, 'project' => $this->project];

        if ($meeting !== null) {
            $params['meeting'] = $meeting;
        }

        return route($name, $params);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function schedule(array $payload = []): TestResponse
    {
        return $this->actingAs($this->owner)->post($this->route('workspace.projects.meetings.store'), array_merge([
            'title' => 'Sprint planning',
            'scheduled_at' => '2026-09-01 10:00:00',
            'duration_minutes' => 30,
        ], $payload));
    }

    public function test_a_meeting_can_be_created_with_existing_user_participants(): void
    {
        $this->schedule(['participant_user_ids' => [$this->projectMember->id]])->assertRedirect();

        $participant = MeetingParticipant::query()->firstOrFail();

        $this->assertSame($this->projectMember->id, $participant->user_id);
        $this->assertSame(mb_strtolower($this->projectMember->email), $participant->email);
        $this->assertSame($this->projectMember->name, $participant->name);
        $this->assertFalse($participant->isExternal());
    }

    public function test_a_meeting_can_be_created_with_an_external_email_participant(): void
    {
        $this->schedule(['participant_emails' => ['External.Person@Example.com']])->assertRedirect();

        $participant = MeetingParticipant::query()->firstOrFail();

        $this->assertNull($participant->user_id);
        $this->assertSame('external.person@example.com', $participant->email);
        $this->assertTrue($participant->isExternal());
    }

    public function test_a_duplicate_participant_email_is_collapsed_to_one_row(): void
    {
        $this->schedule([
            'participant_user_ids' => [$this->projectMember->id],
            'participant_emails' => [mb_strtoupper($this->projectMember->email)],
        ])->assertRedirect();

        $this->assertSame(1, MeetingParticipant::query()->count());
        $this->assertSame($this->projectMember->id, MeetingParticipant::query()->firstOrFail()->user_id);
    }

    public function test_repeated_emails_in_the_payload_are_rejected(): void
    {
        $this->schedule(['participant_emails' => ['dup@example.com', 'DUP@example.com']])
            ->assertSessionHasErrors('participant_emails.1');

        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_an_invalid_participant_email_is_rejected(): void
    {
        $this->schedule(['participant_emails' => ['not-an-email']])
            ->assertSessionHasErrors('participant_emails.0');

        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_a_user_outside_the_project_cannot_be_attached_by_id(): void
    {
        $this->schedule(['participant_user_ids' => [$this->unassignedMember->id]])
            ->assertSessionHasErrors('participant_user_ids.0');

        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_a_user_from_another_workspace_cannot_be_attached_by_id(): void
    {
        $stranger = User::factory()->create();
        Workspace::factory()->ownedBy($stranger)->create();

        $this->schedule(['participant_user_ids' => [$stranger->id]])
            ->assertSessionHasErrors('participant_user_ids.0');

        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_explicit_participants_are_emailed_and_unrelated_members_are_not(): void
    {
        $this->schedule([
            'participant_user_ids' => [$this->projectMember->id],
            'participant_emails' => ['guest@example.com'],
        ])->assertRedirect();

        Mail::assertQueued(MeetingScheduledMail::class, 2);
        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => $mail->hasTo($this->projectMember->email));
        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => $mail->hasTo('guest@example.com'));
        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => ! $mail->hasTo($this->unassignedMember->email));
    }

    public function test_a_meeting_with_no_participants_emails_nobody(): void
    {
        $this->schedule()->assertRedirect();

        Mail::assertNothingQueued();
        $this->assertSame(1, Meeting::query()->count());
    }

    public function test_a_join_token_is_generated_automatically_and_is_unique(): void
    {
        $this->schedule(['title' => 'First'])->assertRedirect();
        $this->schedule(['title' => 'Second'])->assertRedirect();

        $tokens = Meeting::query()->pluck('join_token');

        $this->assertCount(2, $tokens);
        $this->assertCount(2, $tokens->unique());

        foreach ($tokens as $token) {
            $this->assertSame(64, strlen((string) $token));
        }
    }

    public function test_the_project_page_exposes_participants_and_the_join_url(): void
    {
        $this->schedule([
            'participant_user_ids' => [$this->projectMember->id],
            'participant_emails' => ['guest@example.com'],
        ]);

        $meeting = Meeting::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('meetings.0.participants', 2)
                ->where('meetings.0.join_url', route('meetings.join', ['token' => $meeting->join_token])));
    }

    public function test_a_listed_participant_can_open_the_join_page(): void
    {
        $this->schedule(['participant_user_ids' => [$this->projectMember->id]]);

        $meeting = Meeting::query()->firstOrFail();

        $this->actingAs($this->projectMember)
            ->get(route('meetings.join', ['token' => $meeting->join_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('meetings/Join')
                ->where('isInternal', true)
                ->where('meeting.project_name', $this->project->name));
    }

    public function test_a_manager_can_open_the_join_page_without_being_a_participant(): void
    {
        $this->schedule();

        $meeting = Meeting::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->get(route('meetings.join', ['token' => $meeting->join_token]))
            ->assertOk();
    }

    public function test_an_unrelated_project_member_cannot_use_the_authenticated_join_flow(): void
    {
        $this->schedule(['participant_user_ids' => [$this->projectMember->id]]);

        $meeting = Meeting::query()->firstOrFail();

        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($other->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($other)
            ->get(route('meetings.join', ['token' => $meeting->join_token]))
            ->assertForbidden();
    }

    public function test_the_external_join_page_hides_project_internals(): void
    {
        $meeting = Meeting::factory()
            ->forProject($this->project)
            ->createdBy($this->owner)
            ->create(['description' => 'Agenda for guests']);

        $meeting->participants()->create(['user_id' => null, 'email' => 'guest@example.com']);

        $this->get(route('meetings.join', ['token' => $meeting->join_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isInternal', false)
                ->where('meeting.project_name', null))
            ->assertDontSee($this->project->name)
            ->assertDontSee($this->workspace->name);
    }

    public function test_an_unknown_join_token_is_not_found(): void
    {
        $this->get(route('meetings.join', ['token' => 'nope']))->assertNotFound();
    }

    public function test_updating_a_meeting_adds_and_removes_participants(): void
    {
        $this->schedule(['participant_user_ids' => [$this->projectMember->id]]);

        $meeting = Meeting::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->put($this->route('workspace.projects.meetings.update', $meeting), [
                'title' => 'Sprint planning',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'participant_emails' => ['newguest@example.com'],
            ])
            ->assertRedirect();

        $emails = $meeting->participants()->pluck('email')->all();

        $this->assertSame(['newguest@example.com'], $emails);
    }

    public function test_cancellation_emails_reach_explicit_participants_including_externals(): void
    {
        $this->schedule([
            'participant_user_ids' => [$this->projectMember->id],
            'participant_emails' => ['guest@example.com'],
        ]);

        $meeting = Meeting::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->delete($this->route('workspace.projects.meetings.destroy', $meeting))
            ->assertRedirect();

        Mail::assertQueued(MeetingCancelledMail::class, 2);
        Mail::assertQueued(MeetingCancelledMail::class, fn ($mail) => $mail->hasTo($this->projectMember->email));
        Mail::assertQueued(MeetingCancelledMail::class, fn ($mail) => $mail->hasTo('guest@example.com'));
        Mail::assertQueued(MeetingCancelledMail::class, fn ($mail) => ! $mail->hasTo($this->unassignedMember->email));
    }

    public function test_deleting_a_meeting_removes_its_participants(): void
    {
        $this->schedule(['participant_user_ids' => [$this->projectMember->id]]);

        $meeting = Meeting::query()->firstOrFail();

        $this->actingAs($this->owner)->delete($this->route('workspace.projects.meetings.destroy', $meeting));

        $this->assertSame(0, MeetingParticipant::query()->count());
    }
}
