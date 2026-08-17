<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Mail\MemberInvitationMail;
use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class TeamMemberTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->member = User::factory()->create();
        $this->workspace->users()->attach($this->member->id, ['role' => UserRole::MEMBER->value]);
    }

    public function test_the_roster_lists_members_and_pending_invitations(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspace.teams.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('teams/index')
                ->has('members', 2)
                ->where('canManageMembers', true));
    }

    public function test_the_roster_exposes_invite_links_only_to_viewers_who_can_invite(): void
    {
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'invited_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('workspace.teams.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canInviteMembers', true)
                ->where('members.2.invitation_id', $invitation->id)
                ->where('members.2.invite_url', route('workspace.invitations.accept', ['token' => $invitation->token])));

        $this->actingAs($this->member)
            ->get(route('workspace.teams.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canInviteMembers', false)
                ->where('members.2.invite_url', null));
    }

    public function test_a_member_cannot_read_an_invitation_token_from_the_roster_payload(): void
    {
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'invited_by' => $this->owner->id,
        ]);

        $this->actingAs($this->member)
            ->get(route('workspace.teams.index', $this->workspace))
            ->assertOk()
            ->assertDontSee($invitation->token);
    }

    public function test_an_admin_can_resend_and_revoke_an_invitation_from_the_roster(): void
    {
        Mail::fake();

        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'invited_by' => $this->owner->id,
        ]);

        $originalToken = $invitation->token;

        $this->actingAs($this->owner)
            ->post(route('workspace.invitations.resend', [$this->workspace, $invitation]))
            ->assertRedirect();

        $this->assertNotSame($originalToken, $invitation->fresh()->token);
        Mail::assertQueued(MemberInvitationMail::class);

        $this->actingAs($this->owner)
            ->delete(route('workspace.invitations.destroy', [$this->workspace, $invitation]))
            ->assertRedirect();

        $this->assertDatabaseMissing('workspace_invitations', ['id' => $invitation->id]);
    }

    public function test_a_member_cannot_resend_or_revoke_an_invitation(): void
    {
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'invited_by' => $this->owner->id,
        ]);

        $this->actingAs($this->member)
            ->post(route('workspace.invitations.resend', [$this->workspace, $invitation]))
            ->assertForbidden();

        $this->actingAs($this->member)
            ->delete(route('workspace.invitations.destroy', [$this->workspace, $invitation]))
            ->assertForbidden();

        $this->assertDatabaseHas('workspace_invitations', ['id' => $invitation->id]);
    }

    public function test_an_admin_can_assign_a_custom_workspace_role_to_a_member(): void
    {
        $role = WorkspaceRole::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($this->owner)
            ->patch(route('workspace.members.update', [$this->workspace, $this->member]), [
                'role' => UserRole::MEMBER->value,
                'workspace_role_id' => $role->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('workspace_users', [
            'user_id' => $this->member->id,
            'workspace_id' => $this->workspace->id,
            'role' => UserRole::MEMBER->value,
            'workspace_role_id' => $role->id,
        ]);
    }

    public function test_a_custom_role_from_another_workspace_cannot_be_assigned(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $role = WorkspaceRole::factory()->create(['workspace_id' => $other->id]);

        $this->actingAs($this->owner)
            ->patch(route('workspace.members.update', [$this->workspace, $this->member]), [
                'role' => UserRole::MEMBER->value,
                'workspace_role_id' => $role->id,
            ])
            ->assertSessionHasErrors('workspace_role_id');

        $this->assertDatabaseHas('workspace_users', [
            'user_id' => $this->member->id,
            'workspace_id' => $this->workspace->id,
            'workspace_role_id' => null,
        ]);
    }

    public function test_the_roster_exposes_the_assigned_custom_role_and_the_available_roles(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Frontend Dev',
        ]);

        $this->owner->forceFill(['name' => 'Aaron Owner'])->save();
        $this->member->forceFill(['name' => 'Zoe Member'])->save();

        $this->workspace->users()->updateExistingPivot($this->member->id, ['workspace_role_id' => $role->id]);

        $this->actingAs($this->owner)
            ->get(route('workspace.teams.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('workspaceRoles', 1)
                ->where('workspaceRoles.0.name', 'Frontend Dev')
                ->has('members', 2)
                ->where('members.1.workspace_role_id', $role->id)
                ->where('members.1.workspace_role_name', 'Frontend Dev')
                ->where('members.0.workspace_role_name', null));
    }

    public function test_an_admin_can_promote_a_member(): void
    {
        $this->actingAs($this->owner)
            ->patch(route('workspace.members.update', [$this->workspace, $this->member]), [
                'role' => UserRole::ADMIN->value,
            ])
            ->assertRedirect();

        $this->assertSame(UserRole::ADMIN, $this->workspace->fresh()->roleFor($this->member));
    }

    public function test_the_owner_role_cannot_be_assigned(): void
    {
        $this->actingAs($this->owner)
            ->patch(route('workspace.members.update', [$this->workspace, $this->member]), [
                'role' => UserRole::OWNER->value,
            ])
            ->assertSessionHasErrors('role');
    }

    public function test_the_owner_cannot_be_removed(): void
    {
        $admin = User::factory()->create();
        $this->workspace->users()->attach($admin->id, ['role' => UserRole::ADMIN->value]);

        $this->actingAs($admin)
            ->delete(route('workspace.members.destroy', [$this->workspace, $this->owner]))
            ->assertStatus(422);

        $this->assertTrue($this->workspace->fresh()->hasMember($this->owner));
    }

    public function test_a_user_cannot_remove_themselves(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('workspace.members.destroy', [$this->workspace, $this->owner]))
            ->assertStatus(422);
    }

    public function test_a_member_cannot_remove_another_member(): void
    {
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->delete(route('workspace.members.destroy', [$this->workspace, $other]))
            ->assertForbidden();

        $this->assertTrue($this->workspace->fresh()->hasMember($other));
    }

    public function test_removing_a_member_clears_their_current_workspace(): void
    {
        $this->member->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->actingAs($this->owner)
            ->delete(route('workspace.members.destroy', [$this->workspace, $this->member]))
            ->assertRedirect();

        $this->assertFalse($this->workspace->fresh()->hasMember($this->member));
        $this->assertNull($this->member->refresh()->current_workspace_id);
    }

    public function test_a_user_outside_the_workspace_cannot_be_targeted(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->owner)
            ->delete(route('workspace.members.destroy', [$this->workspace, $stranger]))
            ->assertNotFound();
    }
}
