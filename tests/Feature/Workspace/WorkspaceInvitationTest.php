<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Mail\MemberInvitationMail;
use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class WorkspaceInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
    }

    public function test_an_admin_can_invite_a_new_member(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.invitations.store', $this->workspace), [
                'email' => 'newbie@example.com',
                'role' => UserRole::MEMBER->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_id' => $this->workspace->id,
            'email' => 'newbie@example.com',
            'role' => UserRole::MEMBER->value,
        ]);

        Mail::assertQueued(MemberInvitationMail::class);
    }

    public function test_an_existing_member_cannot_be_invited_again(): void
    {
        $member = User::factory()->create(['email' => 'member@example.com']);
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($this->owner)
            ->post(route('workspace.invitations.store', $this->workspace), [
                'email' => 'member@example.com',
                'role' => UserRole::MEMBER->value,
            ])
            ->assertSessionHasErrors('email');

        Mail::assertNothingQueued();
    }

    public function test_the_owner_role_cannot_be_granted_by_invitation(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.invitations.store', $this->workspace), [
                'email' => 'takeover@example.com',
                'role' => UserRole::OWNER->value,
            ])
            ->assertSessionHasErrors('role');
    }

    public function test_a_new_user_can_register_and_accept_an_invitation(): void
    {
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'invited_by' => $this->owner->id,
            'email' => 'invitee@example.com',
        ]);

        $this->get(route('workspace.invitations.accept', $invitation->token))->assertOk();

        $this->post(route('workspace.invitations.accept.store', $invitation->token), [
            'name' => 'New Person',
            'password' => 'Password!2345',
            'password_confirmation' => 'Password!2345',
        ])->assertRedirect(route('dashboard', $this->workspace));

        $user = User::query()->where('email', 'invitee@example.com')->firstOrFail();

        $this->assertTrue($this->workspace->fresh()->hasMember($user));
        $this->assertSame($this->workspace->id, $user->current_workspace_id);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        $invitation = WorkspaceInvitation::factory()->expired()->create([
            'workspace_id' => $this->workspace->id,
            'invited_by' => $this->owner->id,
        ]);

        $this->get(route('workspace.invitations.accept', $invitation->token))
            ->assertRedirect(route('login'));

        $this->post(route('workspace.invitations.accept.store', $invitation->token), [
            'name' => 'Too Late',
            'password' => 'Password!2345',
            'password_confirmation' => 'Password!2345',
        ])->assertStatus(410);
    }

    public function test_an_already_accepted_invitation_cannot_be_reused(): void
    {
        $invitation = WorkspaceInvitation::factory()->accepted()->create([
            'workspace_id' => $this->workspace->id,
            'invited_by' => $this->owner->id,
        ]);

        $this->get(route('workspace.invitations.accept', $invitation->token))
            ->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_cannot_accept_someone_elses_invitation(): void
    {
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'invited_by' => $this->owner->id,
            'email' => 'intended@example.com',
        ]);

        $intruder = User::factory()->create(['email' => 'intruder@example.com']);

        $this->actingAs($intruder)
            ->get(route('workspace.invitations.accept', $invitation->token))
            ->assertRedirect(route('login'));

        $this->actingAs($intruder)
            ->post(route('workspace.invitations.accept.store', $invitation->token))
            ->assertStatus(404);

        $this->assertFalse($this->workspace->fresh()->hasMember($intruder));
    }

    public function test_an_admin_can_revoke_a_pending_invitation(): void
    {
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'invited_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)
            ->delete(route('workspace.invitations.destroy', [$this->workspace, $invitation]))
            ->assertRedirect();

        $this->assertDatabaseMissing('workspace_invitations', ['id' => $invitation->id]);
    }

    public function test_an_invitation_from_another_workspace_cannot_be_revoked(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();

        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'invited_by' => $otherWorkspace->owner_id,
        ]);

        $this->actingAs($this->owner)
            ->delete(route('workspace.invitations.destroy', [$this->workspace, $invitation]))
            ->assertNotFound();

        $this->assertDatabaseHas('workspace_invitations', ['id' => $invitation->id]);
    }
}
