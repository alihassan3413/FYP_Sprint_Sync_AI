<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Mail\MeetingCancelledMail;
use App\Models\User;
use App\Modules\Assistant\Actions\ExecuteToolCall;
use App\Modules\Assistant\Actions\ResolveConversationWorkspace;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Support\ToolArgumentValidator;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\CancelMeetingTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Meetings\Actions\CreateMeetingAction;
use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingParticipant;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\Notifications\MeetingCancelledNotification;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AssistantCancelMeetingToolTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $teammate;

    private Project $project;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->owner = User::factory()->create(['timezone' => 'Asia/Karachi']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mobile App']);

        $this->teammate = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($this->teammate->id, ['role' => ProjectRole::MEMBER->value]);

        $this->meeting = app(CreateMeetingAction::class)->handle($this->project, $this->owner, StoreMeetingData::from([
            'title' => 'Sprint review',
            'description' => 'Review the sprint.',
            'scheduled_at' => '2026-09-01 10:00:00',
            'duration_minutes' => 45,
            'meeting_link' => null,
            'participant_user_ids' => [],
            'participant_emails' => [$this->teammate->email, 'client@acme.com'],
        ]));

        Mail::fake();
        Notification::fake();
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
     * @return array<string, mixed>
     */
    private function cancel(User $user, ?int $meetingId = null): array
    {
        return app(CancelMeetingTool::class)->execute(
            ['meeting_id' => $meetingId ?? $this->meeting->id],
            $this->contextFor($user),
        );
    }

    public function test_the_tool_is_registered_and_requires_confirmation(): void
    {
        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->contextFor($this->owner)),
        );

        $this->assertContains('cancel_meeting', $names);
        $this->assertTrue(app(CancelMeetingTool::class)->requiresConfirmation());
    }

    public function test_it_accepts_only_a_meeting_id(): void
    {
        $this->assertSame(
            ['meeting_id'],
            array_keys(app(CancelMeetingTool::class)->parameters()['properties']),
        );
    }

    public function test_an_admin_can_cancel_a_meeting(): void
    {
        $admin = $this->memberOf(UserRole::ADMIN);

        $result = $this->cancel($admin);

        $this->assertTrue($result['success']);
        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_a_project_manager_can_cancel_a_meeting(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $result = $this->cancel($manager);

        $this->assertTrue($result['success']);
        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_a_plain_project_member_cannot_cancel_a_meeting(): void
    {
        $result = $this->cancel($this->teammate);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertSame(1, Meeting::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_a_workspace_member_outside_the_project_cannot_see_the_meeting(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $result = $this->cancel($outsider);

        $this->assertFalse($result['success']);
        $this->assertSame('meeting_not_found', $result['error_code']);
        $this->assertSame(1, Meeting::query()->count());
    }

    public function test_a_nonexistent_meeting_is_indistinguishable_from_an_inaccessible_one(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $inaccessible = $this->cancel($outsider);
        $missing = $this->cancel($outsider, 999999);

        $this->assertSame($inaccessible['error_code'], $missing['error_code']);
        $this->assertSame($inaccessible['error'], $missing['error']);
    }

    public function test_a_meeting_in_another_workspace_is_unreachable(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $other->users()->attach($this->owner->id, ['role' => UserRole::OWNER->value]);
        $foreignProject = Project::factory()->forWorkspace($other)->create();
        $foreignMeeting = Meeting::factory()->forProject($foreignProject)->createdBy($this->owner)->create();

        $result = $this->cancel($this->owner, $foreignMeeting->id);

        $this->assertFalse($result['success']);
        $this->assertSame('meeting_not_found', $result['error_code']);
        $this->assertNotNull($foreignMeeting->fresh());
    }

    public function test_the_conversation_workspace_wins_over_the_users_current_workspace(): void
    {
        $other = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $other->id])->save();

        $result = app(CancelMeetingTool::class)->execute(
            ['meeting_id' => $this->meeting->id],
            $this->contextFor($this->owner->refresh()),
        );

        $this->assertTrue($result['success']);
    }

    public function test_cancelling_emails_internal_and_external_participants(): void
    {
        $this->cancel($this->owner);

        Mail::assertQueued(MeetingCancelledMail::class, fn (MeetingCancelledMail $mail) => $mail->hasTo($this->teammate->email));
        Mail::assertQueued(MeetingCancelledMail::class, fn (MeetingCancelledMail $mail) => $mail->hasTo('client@acme.com'));
        Notification::assertSentTo($this->teammate, MeetingCancelledNotification::class);
    }

    public function test_cancelling_records_an_audit_entry_and_removes_the_participants(): void
    {
        $this->cancel($this->owner);

        $this->assertSame(0, Meeting::query()->count());
        $this->assertSame(0, MeetingParticipant::query()->count());
        $this->assertTrue(
            AuditLog::query()->where('action', 'meeting.cancelled')->exists(),
        );
    }

    public function test_the_result_reports_what_was_cancelled_without_leaking_recipients(): void
    {
        $joinToken = $this->meeting->join_token;

        $result = $this->cancel($this->owner);

        $encoded = json_encode($result);

        $this->assertSame('Sprint review', $result['meeting']['title']);
        $this->assertSame($this->project->id, $result['meeting']['project_id']);
        $this->assertSame(45, $result['meeting']['duration_minutes']);
        $this->assertSame(2, $result['meeting']['notified_count']);

        $this->assertStringNotContainsString($joinToken, $encoded);
        $this->assertStringNotContainsString('client@acme.com', $encoded);
        $this->assertStringNotContainsString($this->teammate->email, $encoded);
        $this->assertArrayNotHasKey('join_token', $result['meeting']);
    }

    public function test_a_model_supplied_workspace_or_project_id_is_dropped(): void
    {
        $validated = app(ToolArgumentValidator::class)->validate(app(CancelMeetingTool::class), [
            'meeting_id' => $this->meeting->id,
            'workspace_id' => 999,
            'project_id' => 999,
        ]);

        $this->assertSame(['meeting_id'], array_keys($validated));
    }

    public function test_a_missing_meeting_id_fails_schema_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate(app(CancelMeetingTool::class), []);
    }

    public function test_the_confirmation_card_names_everyone_who_will_be_emailed(): void
    {
        $details = app(CancelMeetingTool::class)->confirmationDetails(
            ['meeting_id' => $this->meeting->id],
            $this->contextFor($this->owner),
        );

        $expected = collect([mb_strtolower($this->teammate->email), 'client@acme.com'])->sort()->implode(', ');

        $this->assertSame('Mobile App', $details['project']);
        $this->assertSame('Sprint review', $details['meeting']);
        $this->assertSame('September 1, 2026 3:00 PM (PKT)', $details['when']);
        $this->assertSame('45 min', $details['duration']);
        $this->assertSame($expected, $details['emailing']);
        $this->assertSame('Cancelling deletes the meeting for everyone. This cannot be undone.', $details['warning']);
    }

    public function test_the_confirmation_card_says_so_when_nobody_else_is_invited(): void
    {
        $solo = app(CreateMeetingAction::class)->handle($this->project, $this->owner, StoreMeetingData::from([
            'title' => 'Solo block',
            'description' => null,
            'scheduled_at' => '2026-09-05 09:00:00',
            'duration_minutes' => 30,
            'meeting_link' => null,
            'participant_user_ids' => [],
            'participant_emails' => [],
        ]));

        $details = app(CancelMeetingTool::class)->confirmationDetails(
            ['meeting_id' => $solo->id],
            $this->contextFor($this->owner),
        );

        $this->assertSame('nobody else is on this meeting', $details['emailing']);
    }

    public function test_confirmation_details_do_not_leak_an_inaccessible_meeting(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $details = app(CancelMeetingTool::class)->confirmationDetails(
            ['meeting_id' => $this->meeting->id],
            $this->contextFor($outsider),
        );

        $this->assertSame(['meeting' => 'Unknown meeting'], $details);
    }

    private function pendingCancel(User $user): Message
    {
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        return Message::factory()
            ->pendingTool('cancel_meeting', ['meeting_id' => $this->meeting->id])
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
        $tool = app(CancelMeetingTool::class);
        $args = app(ToolArgumentValidator::class)->validate($tool, $pending->metadata['args'] ?? []);

        return app(ExecuteToolCall::class)->handle(
            $tool,
            $args,
            app(ResolveConversationWorkspace::class)->contextFor($pending->conversation, $user),
        );
    }

    /**
     * Mirrors ConfirmActionController::reject. The HTTP endpoint cannot be driven
     * end to end here because EventStream::respond closes every output buffer.
     */
    private function reject(Message $pending): void
    {
        $pending->update([
            'tool_status' => Message::STATUS_REJECTED,
            'content' => json_encode(['success' => false, 'error' => 'User canceled this action.']),
        ]);
    }

    public function test_a_pending_cancellation_deletes_nothing_until_confirmed(): void
    {
        $this->pendingCancel($this->owner);

        $this->assertSame(1, Meeting::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_confirming_executes_the_stored_arguments(): void
    {
        $pending = $this->pendingCancel($this->owner);

        $result = $this->confirm($pending, $this->owner);

        $this->assertTrue($result['success']);
        $this->assertSame(0, Meeting::query()->count());
        Mail::assertQueued(MeetingCancelledMail::class, fn (MeetingCancelledMail $mail) => $mail->hasTo('client@acme.com'));
    }

    public function test_rejecting_leaves_the_meeting_intact(): void
    {
        $pending = $this->pendingCancel($this->owner);

        $this->reject($pending);

        $this->assertSame(1, Meeting::query()->count());
        $this->assertSame(Message::STATUS_REJECTED, $pending->refresh()->tool_status);
        Mail::assertNothingQueued();

        $this->actingAs($this->owner)
            ->post(route('assistant.confirm'), ['message_id' => $pending->id, 'action' => 'confirm'])
            ->assertNotFound();

        $this->assertSame(1, Meeting::query()->count());
    }

    public function test_stored_arguments_are_the_only_source_of_the_target(): void
    {
        $other = app(CreateMeetingAction::class)->handle($this->project, $this->owner, StoreMeetingData::from([
            'title' => 'Should survive',
            'description' => null,
            'scheduled_at' => '2026-09-09 09:00:00',
            'duration_minutes' => 30,
            'meeting_link' => null,
            'participant_user_ids' => [],
            'participant_emails' => [],
        ]));

        $pending = $this->pendingCancel($this->owner);

        $this->app['request']->merge(['meeting_id' => $other->id]);

        $this->confirm($pending, $this->owner);

        $this->assertNull($this->meeting->fresh());
        $this->assertNotNull($other->fresh());
    }

    public function test_another_user_cannot_confirm_a_pending_cancellation(): void
    {
        $pending = $this->pendingCancel($this->owner);

        $this->actingAs($this->memberOf(UserRole::ADMIN))
            ->post(route('assistant.confirm'), ['message_id' => $pending->id, 'action' => 'confirm'])
            ->assertNotFound();

        $this->assertSame(Message::STATUS_PENDING, $pending->refresh()->tool_status);
        $this->assertSame(1, Meeting::query()->count());
    }

    public function test_execute_tool_call_refuses_the_tool_without_workspace_context(): void
    {
        $conversation = Conversation::create(['user_id' => $this->owner->id, 'workspace_id' => null]);
        $context = app(ResolveConversationWorkspace::class)->contextFor($conversation, $this->owner);

        $result = app(ExecuteToolCall::class)->handle(app(CancelMeetingTool::class), [], $context);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertSame(1, Meeting::query()->count());
    }
}
