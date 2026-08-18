<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\UserRole;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class EmailVerificationEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function unverifiedMemberOf(Workspace $workspace): User
    {
        $user = User::factory()->unverified()->create();
        $workspace->users()->attach($user->id, ['role' => UserRole::MEMBER->value]);
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return $user->refresh();
    }

    public function test_the_user_model_requires_email_verification(): void
    {
        $this->assertInstanceOf(MustVerifyEmail::class, new User);
    }

    public function test_registration_creates_an_unverified_user_and_sends_the_verification_email(): void
    {
        Notification::fake();

        $this->post(route('register'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect();

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_a_newly_registered_user_is_sent_to_the_verification_notice(): void
    {
        Notification::fake();

        $this->post(route('register'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ]);

        $this->get(route('dashboard', User::query()->where('email', 'ada@example.com')->firstOrFail()->activeWorkspaceOrFail()))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_an_unverified_user_is_redirected_from_a_tenant_route(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();
        $member = $this->unverifiedMemberOf($workspace);

        $this->actingAs($member)
            ->get(route('dashboard', $workspace))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($member)
            ->get(route('workspace.projects.index', $workspace))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_a_verified_user_can_reach_a_tenant_route(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();
        $owner->forceFill(['current_workspace_id' => $workspace->id])->save();

        $this->actingAs($owner)->get(route('dashboard', $workspace))->assertOk();
    }

    public function test_an_unverified_user_cannot_chat_with_the_assistant(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();
        $member = $this->unverifiedMemberOf($workspace);

        $this->actingAs($member)
            ->postJson(route('assistant.chat'), ['message' => 'hello'])
            ->assertForbidden();

        $this->assertSame(0, Conversation::query()->count());
    }

    public function test_an_unverified_user_cannot_confirm_an_assistant_write_action(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();
        $member = $this->unverifiedMemberOf($workspace);

        $conversation = Conversation::factory()->create(['user_id' => $member->id]);
        $pending = Message::factory()
            ->pendingTool('create_workspace', ['name' => 'Sneaky'])
            ->create(['conversation_id' => $conversation->id]);

        $this->actingAs($member)
            ->postJson(route('assistant.confirm'), [
                'message_id' => $pending->id,
                'action' => 'confirm',
            ])
            ->assertForbidden();

        $this->assertSame(Message::STATUS_PENDING, $pending->refresh()->tool_status);
        $this->assertSame(0, Workspace::query()->where('name', 'Sneaky')->count());
    }

    public function test_an_unverified_user_can_still_reach_profile_settings_to_resend(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();
        $member = $this->unverifiedMemberOf($workspace);

        $this->actingAs($member)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Profile')
                ->where('mustVerifyEmail', true));
    }

    public function test_a_verified_user_still_sees_the_must_verify_flag(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('mustVerifyEmail', true));
    }

    public function test_the_resend_verification_action_sends_a_new_notification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_an_invited_user_is_verified_by_accepting_their_invitation(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $owner->id,
            'email' => 'invitee@example.com',
        ]);

        $this->post(route('workspace.invitations.accept.store', $invitation->token), [
            'name' => 'Invited Person',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect(route('dashboard', $workspace));

        $invitee = User::query()->where('email', 'invitee@example.com')->firstOrFail();

        $this->assertTrue($invitee->hasVerifiedEmail());

        $this->actingAs($invitee)->get(route('dashboard', $workspace))->assertOk();
    }
}
