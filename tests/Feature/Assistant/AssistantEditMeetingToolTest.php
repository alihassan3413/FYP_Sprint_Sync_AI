<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Mail\MeetingUpdatedMail;
use App\Models\User;
use App\Modules\Assistant\Actions\ExecuteToolCall;
use App\Modules\Assistant\Actions\ResolveConversationWorkspace;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Support\ToolArgumentValidator;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\EditMeetingTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Meetings\Actions\CreateMeetingAction;
use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AssistantEditMeetingToolTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

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

        $this->meeting = app(CreateMeetingAction::class)->handle($this->project, $this->owner, StoreMeetingData::from([
            'title' => 'Sprint review',
            'description' => 'Review the sprint.',
            'scheduled_at' => '2026-09-01 10:00:00',
            'duration_minutes' => 45,
            'meeting_link' => null,
            'participant_user_ids' => [],
            'participant_emails' => ['client@acme.com'],
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
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function edit(User $user, array $args = []): array
    {
        return app(EditMeetingTool::class)->execute(
            array_merge(['meeting_id' => $this->meeting->id], $args),
            $this->contextFor($user),
        );
    }

    public function test_the_tool_is_registered_and_requires_confirmation(): void
    {
        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->contextFor($this->owner)),
        );

        $this->assertContains('edit_meeting', $names);
        $this->assertTrue(app(EditMeetingTool::class)->requiresConfirmation());
    }

    public function test_an_admin_can_edit_a_meeting(): void
    {
        $admin = $this->memberOf(UserRole::ADMIN);

        $result = $this->edit($admin, ['title' => 'Sprint review v2']);

        $this->assertTrue($result['success']);
        $this->assertSame('Sprint review v2', $this->meeting->refresh()->title);
    }

    public function test_a_project_manager_can_edit_a_meeting(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $result = $this->edit($manager, ['title' => 'Managed retitle']);

        $this->assertTrue($result['success']);
        $this->assertSame('Managed retitle', $this->meeting->refresh()->title);
    }

    public function test_a_plain_project_member_cannot_edit_a_meeting(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $result = $this->edit($member, ['title' => 'Nope']);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertSame('Sprint review', $this->meeting->refresh()->title);
        Mail::assertNothingQueued();
    }

    public function test_a_workspace_member_outside_the_project_cannot_see_the_meeting(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $result = $this->edit($outsider, ['title' => 'Sneaky']);

        $this->assertFalse($result['success']);
        $this->assertSame('meeting_not_found', $result['error_code']);
        $this->assertSame('Sprint review', $this->meeting->refresh()->title);
    }

    public function test_a_nonexistent_meeting_is_indistinguishable_from_an_inaccessible_one(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $inaccessible = app(EditMeetingTool::class)->execute(
            ['meeting_id' => $this->meeting->id, 'title' => 'Sneaky'],
            $this->contextFor($outsider),
        );

        $missing = app(EditMeetingTool::class)->execute(
            ['meeting_id' => 999999, 'title' => 'Sneaky'],
            $this->contextFor($outsider),
        );

        $this->assertSame($inaccessible['error_code'], $missing['error_code']);
        $this->assertSame($inaccessible['error'], $missing['error']);
    }

    public function test_a_meeting_in_another_workspace_is_unreachable(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $other->users()->attach($this->owner->id, ['role' => UserRole::OWNER->value]);
        $foreignProject = Project::factory()->forWorkspace($other)->create();
        $foreignMeeting = Meeting::factory()->forProject($foreignProject)->createdBy($this->owner)->create();

        $result = app(EditMeetingTool::class)->execute(
            ['meeting_id' => $foreignMeeting->id, 'title' => 'Cross tenant'],
            $this->contextFor($this->owner),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('meeting_not_found', $result['error_code']);
    }

    public function test_the_conversation_workspace_wins_over_the_users_current_workspace(): void
    {
        $other = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $other->id])->save();

        $result = app(EditMeetingTool::class)->execute(
            ['meeting_id' => $this->meeting->id, 'title' => 'Still fine'],
            $this->contextFor($this->owner->refresh()),
        );

        $this->assertTrue($result['success']);
    }

    public function test_omitted_fields_keep_their_current_values(): void
    {
        $this->edit($this->owner, ['title' => 'Only the title moved']);

        $this->meeting->refresh();

        $this->assertSame('Only the title moved', $this->meeting->title);
        $this->assertSame('Review the sprint.', $this->meeting->description);
        $this->assertSame(45, $this->meeting->duration_minutes);
        $this->assertSame('2026-09-01 10:00:00', $this->meeting->scheduled_at->toDateTimeString());
        $this->assertSame(['client@acme.com'], $this->meeting->participants()->pluck('email')->all());
    }

    public function test_a_new_time_is_interpreted_in_the_users_timezone_and_stored_as_utc(): void
    {
        $result = $this->edit($this->owner, ['scheduled_at' => '2026-09-02 18:30']);

        $this->assertTrue($result['success']);
        $this->assertSame('2026-09-02 13:30:00', $this->meeting->refresh()->scheduled_at->toDateTimeString());
    }

    public function test_a_user_without_a_timezone_falls_back_to_the_application_timezone(): void
    {
        $this->owner->forceFill(['timezone' => null])->save();

        $this->edit($this->owner->refresh(), ['scheduled_at' => '2026-09-02 18:30']);

        $expected = now()->parse('2026-09-02 18:30', config('app.timezone'))->utc()->toDateTimeString();

        $this->assertSame($expected, $this->meeting->refresh()->scheduled_at->toDateTimeString());
    }

    public function test_participants_can_be_added_and_removed_in_one_edit(): void
    {
        $teammate = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($teammate->id, ['role' => ProjectRole::MEMBER->value]);

        $result = $this->edit($this->owner, [
            'participant_emails' => [$teammate->email, 'newguest@example.com'],
        ]);

        $this->assertTrue($result['success']);

        $emails = $this->meeting->participants()->pluck('email')->sort()->values()->all();
        $expected = collect([mb_strtolower($teammate->email), 'newguest@example.com'])->sort()->values()->all();

        $this->assertSame($expected, $emails);
        $this->assertNotContains('client@acme.com', $emails);
    }

    public function test_an_email_belonging_to_a_project_member_becomes_an_internal_participant(): void
    {
        $teammate = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($teammate->id, ['role' => ProjectRole::MEMBER->value]);

        $this->edit($this->owner, ['participant_emails' => [$teammate->email]]);

        $participant = $this->meeting->participants()->firstOrFail();

        $this->assertSame($teammate->id, $participant->user_id);
        $this->assertFalse($participant->isExternal());
    }

    public function test_an_external_participant_is_emailed_the_update(): void
    {
        $this->edit($this->owner, ['title' => 'Sprint review moved']);

        Mail::assertQueued(MeetingUpdatedMail::class, fn (MeetingUpdatedMail $mail) => $mail->hasTo('client@acme.com'));
    }

    public function test_participants_are_preserved_when_the_list_is_not_supplied(): void
    {
        $this->edit($this->owner, ['duration_minutes' => 60]);

        $this->assertSame(['client@acme.com'], $this->meeting->participants()->pluck('email')->all());
    }

    public function test_a_no_op_update_notifies_nobody(): void
    {
        $result = $this->edit($this->owner, [
            'title' => 'Sprint review',
            'duration_minutes' => 45,
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['changed']);

        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    public function test_an_edit_with_no_editable_fields_is_rejected(): void
    {
        $result = $this->edit($this->owner);

        $this->assertFalse($result['success']);
        $this->assertSame('nothing_to_change', $result['error_code']);
        Mail::assertNothingQueued();
    }

    public function test_the_result_never_exposes_the_join_token_or_provider_link(): void
    {
        $this->meeting->forceFill(['meeting_link' => 'https://zoom.example.com/secret'])->save();

        $result = $this->edit($this->owner, ['title' => 'Sprint review renamed']);

        $encoded = json_encode($result);

        $this->assertStringNotContainsString($this->meeting->refresh()->join_token, $encoded);
        $this->assertStringNotContainsString('zoom.example.com', $encoded);
        $this->assertStringNotContainsString('client@acme.com', $encoded);
        $this->assertArrayNotHasKey('join_token', $result['meeting']);
    }

    public function test_the_existing_provider_link_survives_an_unrelated_edit(): void
    {
        $this->meeting->forceFill(['meeting_link' => 'https://zoom.example.com/room'])->save();

        $this->edit($this->owner, ['title' => 'Renamed again']);

        $this->assertSame('https://zoom.example.com/room', $this->meeting->refresh()->meeting_link);
    }

    public function test_an_invalid_participant_email_is_rejected_and_nothing_changes(): void
    {
        $result = $this->edit($this->owner, [
            'title' => 'Should not stick',
            'participant_emails' => ['not-an-email'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_arguments', $result['error_code']);
        $this->assertSame('Sprint review', $this->meeting->refresh()->title);
        Mail::assertNothingQueued();
    }

    public function test_a_model_supplied_workspace_or_project_id_is_dropped(): void
    {
        $validated = app(ToolArgumentValidator::class)->validate(app(EditMeetingTool::class), [
            'meeting_id' => $this->meeting->id,
            'title' => 'Sprint review',
            'workspace_id' => 999,
            'project_id' => 999,
            'participant_user_ids' => [1, 2],
        ]);

        $this->assertArrayNotHasKey('workspace_id', $validated);
        $this->assertArrayNotHasKey('project_id', $validated);
        $this->assertArrayNotHasKey('participant_user_ids', $validated);
    }

    public function test_a_malformed_datetime_fails_schema_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate(app(EditMeetingTool::class), [
            'meeting_id' => $this->meeting->id,
            'scheduled_at' => 'sometime friday',
        ]);
    }

    public function test_the_confirmation_card_shows_the_change_and_the_local_time(): void
    {
        $details = app(EditMeetingTool::class)->confirmationDetails([
            'meeting_id' => $this->meeting->id,
            'title' => 'Sprint review v2',
            'scheduled_at' => '2026-09-02 18:30',
        ], $this->contextFor($this->owner));

        $this->assertSame('Mobile App', $details['project']);
        $this->assertSame('Sprint review', $details['meeting']);
        $this->assertSame('Sprint review → Sprint review v2', $details['title']);
        $this->assertSame(
            'September 1, 2026 3:00 PM (PKT) → September 2, 2026 6:30 PM (PKT)',
            $details['when'],
        );
        $this->assertSame('45 min', $details['duration']);
        $this->assertSame('1 existing participant', $details['notifies']);
    }

    public function test_the_confirmation_card_lists_the_resulting_participants(): void
    {
        $details = app(EditMeetingTool::class)->confirmationDetails([
            'meeting_id' => $this->meeting->id,
            'participant_emails' => ['client@acme.com', 'newguest@example.com'],
        ], $this->contextFor($this->owner));

        $this->assertSame('client@acme.com, newguest@example.com', $details['participants']);
        $this->assertSame('newguest@example.com', $details['adding']);
        $this->assertArrayNotHasKey('removing', $details);
    }

    public function test_the_confirmation_card_names_who_is_being_removed(): void
    {
        $details = app(EditMeetingTool::class)->confirmationDetails([
            'meeting_id' => $this->meeting->id,
            'participant_emails' => ['newguest@example.com'],
        ], $this->contextFor($this->owner));

        $this->assertSame('newguest@example.com', $details['adding']);
        $this->assertSame('client@acme.com', $details['removing']);
    }

    public function test_the_confirmation_card_flags_a_no_op_edit(): void
    {
        $details = app(EditMeetingTool::class)->confirmationDetails([
            'meeting_id' => $this->meeting->id,
            'title' => 'Sprint review',
        ], $this->contextFor($this->owner));

        $this->assertSame('Nothing changes — nobody will be emailed.', $details['changes']);
    }

    public function test_confirmation_details_do_not_leak_an_inaccessible_meeting(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $details = app(EditMeetingTool::class)->confirmationDetails(
            ['meeting_id' => $this->meeting->id, 'title' => 'Peek'],
            $this->contextFor($outsider),
        );

        $this->assertSame(['meeting' => 'Unknown meeting'], $details);
    }

    private function pendingEdit(User $user): Message
    {
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        return Message::factory()
            ->pendingTool('edit_meeting', [
                'meeting_id' => $this->meeting->id,
                'title' => 'Confirmed rename',
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
        $tool = app(EditMeetingTool::class);
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

    public function test_a_pending_edit_changes_nothing_until_confirmed(): void
    {
        $this->pendingEdit($this->owner);

        $this->assertSame('Sprint review', $this->meeting->refresh()->title);
        Mail::assertNothingQueued();
    }

    public function test_confirming_executes_the_stored_arguments(): void
    {
        $pending = $this->pendingEdit($this->owner);

        $result = $this->confirm($pending, $this->owner);

        $this->assertTrue($result['success']);
        $this->assertSame('Confirmed rename', $this->meeting->refresh()->title);
        Mail::assertQueued(MeetingUpdatedMail::class, fn (MeetingUpdatedMail $mail) => $mail->hasTo('client@acme.com'));
    }

    public function test_rejecting_leaves_the_meeting_untouched(): void
    {
        $pending = $this->pendingEdit($this->owner);

        $this->reject($pending);

        $this->assertSame('Sprint review', $this->meeting->refresh()->title);
        $this->assertSame(Message::STATUS_REJECTED, $pending->refresh()->tool_status);
        Mail::assertNothingQueued();

        $this->actingAs($this->owner)
            ->post(route('assistant.confirm'), ['message_id' => $pending->id, 'action' => 'confirm'])
            ->assertNotFound();

        $this->assertSame('Sprint review', $this->meeting->refresh()->title);
    }

    public function test_stored_arguments_are_the_only_source_of_the_edit(): void
    {
        $pending = $this->pendingEdit($this->owner);

        $this->app['request']->merge([
            'title' => 'Hijacked',
            'participant_emails' => ['attacker@evil.com'],
        ]);

        $this->confirm($pending, $this->owner);

        $this->meeting->refresh();

        $this->assertSame('Confirmed rename', $this->meeting->title);
        $this->assertSame(['client@acme.com'], $this->meeting->participants()->pluck('email')->all());
        Mail::assertNotQueued(MeetingUpdatedMail::class, fn (MeetingUpdatedMail $mail) => $mail->hasTo('attacker@evil.com'));
    }

    public function test_another_user_cannot_confirm_a_pending_edit(): void
    {
        $pending = $this->pendingEdit($this->owner);

        $this->actingAs($this->memberOf(UserRole::ADMIN))
            ->post(route('assistant.confirm'), ['message_id' => $pending->id, 'action' => 'confirm'])
            ->assertNotFound();

        $this->assertSame(Message::STATUS_PENDING, $pending->refresh()->tool_status);
        $this->assertSame('Sprint review', $this->meeting->refresh()->title);
    }

    public function test_execute_tool_call_refuses_the_tool_without_workspace_context(): void
    {
        $conversation = Conversation::create(['user_id' => $this->owner->id, 'workspace_id' => null]);
        $context = app(ResolveConversationWorkspace::class)->contextFor($conversation, $this->owner);

        $result = app(ExecuteToolCall::class)->handle(app(EditMeetingTool::class), [], $context);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertSame('Sprint review', $this->meeting->refresh()->title);
    }
}
