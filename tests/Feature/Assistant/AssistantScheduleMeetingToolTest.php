<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Mail\MeetingScheduledMail;
use App\Models\User;
use App\Modules\Assistant\Actions\ExecuteToolCall;
use App\Modules\Assistant\Actions\ResolveConversationWorkspace;
use App\Modules\Assistant\Http\Requests\ConfirmActionRequest;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Support\ToolArgumentValidator;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\ListMeetingsTool;
use App\Modules\Assistant\Tools\ScheduleMeetingTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AssistantScheduleMeetingToolTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mobile App']);
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
    private function schedule(User $user, array $args = []): array
    {
        return app(ScheduleMeetingTool::class)->execute(array_merge([
            'project_id' => $this->project->id,
            'title' => 'Sprint review',
            'scheduled_at' => '2026-09-01 15:00',
            'duration_minutes' => 45,
        ], $args), $this->contextFor($user));
    }

    public function test_the_tool_is_registered_and_requires_confirmation(): void
    {
        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->contextFor($this->owner)),
        );

        $this->assertContains('schedule_meeting', $names);
        $this->assertTrue(app(ScheduleMeetingTool::class)->requiresConfirmation());
    }

    public function test_an_owner_can_schedule_a_meeting_with_internal_and_external_participants(): void
    {
        $teammate = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($teammate->id, ['role' => ProjectRole::MEMBER->value]);

        $result = $this->schedule($this->owner, [
            'description' => 'Demo the new flows.',
            'participant_emails' => [$teammate->email, 'client@acme.com'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Mobile App', $result['meeting']['project_name']);
        $this->assertSame(2, $result['meeting']['participant_count']);

        $meeting = Meeting::query()->firstOrFail();

        $this->assertSame('Sprint review', $meeting->title);
        $this->assertSame(45, $meeting->duration_minutes);
        $this->assertSame('Demo the new flows.', $meeting->description);
        $this->assertSame(2, $meeting->participants()->count());
        $this->assertSame(1, $meeting->participants()->external()->count());
    }

    public function test_the_meetings_domain_still_sends_the_invitations(): void
    {
        $this->schedule($this->owner, ['participant_emails' => ['client@acme.com']]);

        Mail::assertQueued(MeetingScheduledMail::class, 1);
        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => $mail->hasTo('client@acme.com'));
    }

    public function test_a_generated_join_link_is_created_and_never_returned_to_the_model(): void
    {
        $result = $this->schedule($this->owner, ['participant_emails' => ['client@acme.com']]);

        $meeting = Meeting::query()->firstOrFail();

        $this->assertSame(64, strlen((string) $meeting->join_token));

        $serialised = (string) json_encode($result);

        $this->assertStringNotContainsString((string) $meeting->join_token, $serialised);
        $this->assertStringNotContainsString('client@acme.com', $serialised);
        $this->assertArrayNotHasKey('join_token', $result['meeting']);
        $this->assertArrayNotHasKey('participants', $result['meeting']);
    }

    public function test_scheduling_writes_an_audit_entry(): void
    {
        $this->schedule($this->owner);

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'user_id' => $this->owner->id,
            'action' => 'meeting.scheduled',
        ]);
    }

    public function test_list_meetings_sees_the_new_meeting(): void
    {
        $this->schedule($this->owner, ['scheduled_at' => now()->addDay()->format('Y-m-d H:i')]);

        $listed = app(ListMeetingsTool::class)->execute([], $this->contextFor($this->owner));

        $this->assertSame(['Sprint review'], array_column($listed['meetings'], 'title'));
    }

    public function test_a_project_manager_can_schedule_but_a_plain_member_cannot(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $this->assertTrue($this->schedule($manager)['success']);

        $member = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $result = $this->schedule($member);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertSame(1, Meeting::query()->count());
    }

    public function test_a_plain_member_is_not_offered_the_tool(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->contextFor($member)),
        );

        $this->assertNotContains('schedule_meeting', $names);
    }

    public function test_an_unassigned_workspace_member_cannot_reach_the_project(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $result = app(ScheduleMeetingTool::class)->execute([
            'project_id' => $this->project->id,
            'title' => 'Sneaky',
            'scheduled_at' => '2026-09-01 15:00',
            'duration_minutes' => 30,
        ], $this->contextFor($outsider));

        $this->assertFalse($result['success']);
        $this->assertSame('project_not_found', $result['error_code']);
        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_a_project_in_another_workspace_is_unreachable(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $other->users()->attach($this->owner->id, ['role' => UserRole::OWNER->value]);
        $foreign = Project::factory()->forWorkspace($other)->create();

        $result = $this->schedule($this->owner, ['project_id' => $foreign->id]);

        $this->assertFalse($result['success']);
        $this->assertSame('project_not_found', $result['error_code']);
        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_the_conversation_workspace_wins_over_the_users_current_workspace(): void
    {
        $other = Workspace::factory()->ownedBy($this->owner)->create();
        $foreign = Project::factory()->forWorkspace($other)->create();

        $this->owner->forceFill(['current_workspace_id' => $other->id])->save();

        $result = app(ScheduleMeetingTool::class)->execute([
            'project_id' => $foreign->id,
            'title' => 'Wrong tenant',
            'scheduled_at' => '2026-09-01 15:00',
            'duration_minutes' => 30,
        ], $this->contextFor($this->owner->refresh()));

        $this->assertFalse($result['success']);
        $this->assertSame('project_not_found', $result['error_code']);
        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_an_invalid_participant_email_is_rejected_and_nothing_is_created(): void
    {
        $result = $this->schedule($this->owner, ['participant_emails' => ['not-an-email']]);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_arguments', $result['error_code']);
        $this->assertSame(0, Meeting::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_duplicate_participant_emails_are_rejected(): void
    {
        $result = $this->schedule($this->owner, ['participant_emails' => ['dup@example.com', 'DUP@example.com']]);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_arguments', $result['error_code']);
        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_a_model_supplied_workspace_id_or_participant_user_id_is_dropped(): void
    {
        $validated = app(ToolArgumentValidator::class)->validate(app(ScheduleMeetingTool::class), [
            'project_id' => $this->project->id,
            'title' => 'Sprint review',
            'scheduled_at' => '2026-09-01 15:00',
            'duration_minutes' => 30,
            'workspace_id' => 999,
            'participant_user_ids' => [1, 2],
        ]);

        $this->assertArrayNotHasKey('workspace_id', $validated);
        $this->assertArrayNotHasKey('participant_user_ids', $validated);
    }

    public function test_a_missing_title_fails_schema_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate(app(ScheduleMeetingTool::class), [
            'project_id' => $this->project->id,
            'scheduled_at' => '2026-09-01 15:00',
            'duration_minutes' => 30,
        ]);
    }

    public function test_an_out_of_range_duration_fails_schema_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate(app(ScheduleMeetingTool::class), [
            'project_id' => $this->project->id,
            'title' => 'Sprint review',
            'scheduled_at' => '2026-09-01 15:00',
            'duration_minutes' => 5000,
        ]);
    }

    public function test_a_malformed_datetime_fails_schema_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate(app(ScheduleMeetingTool::class), [
            'project_id' => $this->project->id,
            'title' => 'Sprint review',
            'scheduled_at' => 'sometime friday',
            'duration_minutes' => 30,
        ]);
    }

    public function test_the_confirmation_details_resolve_the_project_name_and_recipient_count(): void
    {
        $details = app(ScheduleMeetingTool::class)->confirmationDetails([
            'project_id' => $this->project->id,
            'participant_emails' => ['a@example.com', 'b@example.com'],
        ], $this->contextFor($this->owner));

        $this->assertSame('Mobile App', $details['project']);
        $this->assertSame('2', $details['participants']);
    }

    public function test_confirmation_details_do_not_leak_an_inaccessible_project_name(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $foreign = Project::factory()->forWorkspace($other)->create(['name' => 'Secret Project']);

        $details = app(ScheduleMeetingTool::class)->confirmationDetails(
            ['project_id' => $foreign->id],
            $this->contextFor($this->owner),
        );

        $this->assertSame('Unknown project', $details['project']);
    }

    private function pendingSchedule(User $user): Message
    {
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        return Message::factory()
            ->pendingTool('schedule_meeting', [
                'project_id' => $this->project->id,
                'title' => 'Sprint review',
                'scheduled_at' => '2026-09-01 15:00',
                'duration_minutes' => 45,
                'participant_emails' => ['client@acme.com'],
            ])
            ->create(['conversation_id' => $conversation->id]);
    }

    /**
     * Mirrors ConfirmActionController::execute — stored arguments are revalidated
     * and executed; the request body is never consulted.
     *
     * @return array<string, mixed>
     */
    private function confirm(Message $pending, User $user): array
    {
        $tool = app(ScheduleMeetingTool::class);
        $args = app(ToolArgumentValidator::class)->validate($tool, $pending->metadata['args'] ?? []);

        return app(ExecuteToolCall::class)->handle(
            $tool,
            $args,
            app(ResolveConversationWorkspace::class)->contextFor($pending->conversation, $user),
        );
    }

    public function test_a_pending_schedule_creates_nothing_until_confirmed(): void
    {
        $this->pendingSchedule($this->owner);

        $this->assertSame(0, Meeting::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_confirming_executes_the_stored_arguments(): void
    {
        $pending = $this->pendingSchedule($this->owner);

        $result = $this->confirm($pending, $this->owner);

        $this->assertTrue($result['success']);

        $meeting = Meeting::query()->firstOrFail();

        $this->assertSame('Sprint review', $meeting->title);
        $this->assertSame(['client@acme.com'], $meeting->participants()->pluck('email')->all());
        Mail::assertQueued(MeetingScheduledMail::class, fn ($mail) => $mail->hasTo('client@acme.com'));
    }

    public function test_the_confirmation_endpoint_only_accepts_a_message_id_and_an_action(): void
    {
        $rules = array_keys((new ConfirmActionRequest)->rules());

        $this->assertSame(['message_id', 'action'], $rules);
    }

    public function test_stored_arguments_are_the_only_source_of_recipients(): void
    {
        $pending = $this->pendingSchedule($this->owner);

        $this->app['request']->merge([
            'participant_emails' => ['attacker@evil.com'],
            'title' => 'Hijacked',
        ]);

        $this->confirm($pending, $this->owner);

        $meeting = Meeting::query()->firstOrFail();

        $this->assertSame('Sprint review', $meeting->title);
        $this->assertSame(['client@acme.com'], $meeting->participants()->pluck('email')->all());
        Mail::assertNotQueued(MeetingScheduledMail::class, fn ($mail) => $mail->hasTo('attacker@evil.com'));
    }

    public function test_another_user_cannot_confirm_a_pending_schedule(): void
    {
        $pending = $this->pendingSchedule($this->owner);

        $this->actingAs($this->memberOf(UserRole::ADMIN))
            ->post(route('assistant.confirm'), ['message_id' => $pending->id, 'action' => 'confirm'])
            ->assertNotFound();

        $this->assertSame(Message::STATUS_PENDING, $pending->refresh()->tool_status);
        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_execute_tool_call_refuses_the_tool_without_workspace_context(): void
    {
        $conversation = Conversation::create(['user_id' => $this->owner->id, 'workspace_id' => null]);
        $context = app(ResolveConversationWorkspace::class)->contextFor($conversation, $this->owner);

        $result = app(ExecuteToolCall::class)->handle(app(ScheduleMeetingTool::class), [], $context);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertSame(0, Meeting::query()->count());
    }
}
