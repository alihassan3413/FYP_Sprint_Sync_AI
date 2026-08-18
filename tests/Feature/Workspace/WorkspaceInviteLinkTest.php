<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInviteLink;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceInviteLinkTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create(['name' => 'Alpha']);
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user;
    }

    private function activeLink(): WorkspaceInviteLink
    {
        return WorkspaceInviteLink::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_an_owner_can_generate_an_invite_link_valid_for_seven_days(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.invite-link.store', $this->workspace))
            ->assertRedirect();

        $link = WorkspaceInviteLink::query()->firstOrFail();

        $this->assertSame($this->workspace->id, $link->workspace_id);
        $this->assertSame(64, strlen($link->token));
        $this->assertSame(0, $link->uses);
        $this->assertTrue($link->isUsable());
        $this->assertEqualsWithDelta(7 * 24, now()->diffInHours($link->expires_at), 1);
    }

    public function test_regenerating_revokes_the_previous_link(): void
    {
        $first = $this->activeLink();

        $this->actingAs($this->owner)->post(route('workspace.invite-link.store', $this->workspace));

        $this->assertTrue($first->refresh()->isRevoked());
        $this->assertSame(1, WorkspaceInviteLink::query()->whereNull('revoked_at')->count());
    }

    public function test_an_admin_can_generate_a_link_but_a_member_cannot(): void
    {
        $admin = $this->memberOf(UserRole::ADMIN);
        $member = $this->memberOf(UserRole::MEMBER);

        $this->actingAs($admin)->post(route('workspace.invite-link.store', $this->workspace))->assertRedirect();

        $this->actingAs($member)->post(route('workspace.invite-link.store', $this->workspace))->assertForbidden();
        $this->actingAs($member)->delete(route('workspace.invite-link.destroy', $this->workspace))->assertForbidden();

        $this->assertSame(1, WorkspaceInviteLink::query()->count());
    }

    public function test_an_owner_can_revoke_the_link(): void
    {
        $link = $this->activeLink();

        $this->actingAs($this->owner)
            ->delete(route('workspace.invite-link.destroy', $this->workspace))
            ->assertRedirect();

        $this->assertTrue($link->refresh()->isRevoked());
    }

    public function test_a_signed_in_user_can_join_through_the_link_as_a_member(): void
    {
        $link = $this->activeLink();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post(route('workspace.join.store', $link->token))
            ->assertRedirect(route('dashboard', $this->workspace));

        $this->assertTrue($this->workspace->fresh()->hasMember($outsider));
        $this->assertSame(UserRole::MEMBER, $this->workspace->roleFor($outsider->refresh()));
        $this->assertSame($this->workspace->id, $outsider->current_workspace_id);
        $this->assertSame(1, $link->refresh()->uses);
    }

    public function test_the_link_is_multi_use(): void
    {
        $link = $this->activeLink();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs(User::factory()->create())->post(route('workspace.join.store', $link->token));
        }

        $this->assertSame(3, $link->refresh()->uses);
        $this->assertSame(4, $this->workspace->users()->count());
    }

    public function test_a_new_user_can_register_through_the_link(): void
    {
        $link = $this->activeLink();

        $this->post(route('workspace.join.store', $link->token), [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect(route('dashboard', $this->workspace));

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();

        $this->assertTrue($this->workspace->fresh()->hasMember($user));
        $this->assertSame(UserRole::MEMBER, $this->workspace->roleFor($user));
    }

    public function test_registering_with_an_existing_email_is_rejected_with_guidance(): void
    {
        $link = $this->activeLink();
        $existing = User::factory()->create(['email' => 'taken@example.com']);

        $this->post(route('workspace.join.store', $link->token), [
            'name' => 'Impostor',
            'email' => 'taken@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertSessionHasErrors('email');

        $this->assertFalse($this->workspace->fresh()->hasMember($existing));
    }

    public function test_a_revoked_link_cannot_be_used(): void
    {
        $link = WorkspaceInviteLink::factory()->revoked()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('workspace.join.show', $link->token))->assertRedirect(route('login'));

        $this->actingAs($outsider)->post(route('workspace.join.store', $link->token))->assertStatus(404);

        $this->assertFalse($this->workspace->fresh()->hasMember($outsider));
    }

    public function test_an_expired_link_cannot_be_used(): void
    {
        $link = WorkspaceInviteLink::factory()->expired()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('workspace.join.show', $link->token))->assertRedirect(route('login'));

        $this->actingAs($outsider)->post(route('workspace.join.store', $link->token))->assertStatus(410);

        $this->assertFalse($this->workspace->fresh()->hasMember($outsider));
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('workspace.join.show', 'does-not-exist'))
            ->assertNotFound();
    }

    public function test_an_existing_member_is_sent_to_the_dashboard_instead_of_joining_again(): void
    {
        $link = $this->activeLink();
        $member = $this->memberOf(UserRole::MEMBER);

        $this->actingAs($member)
            ->get(route('workspace.join.show', $link->token))
            ->assertRedirect(route('dashboard', $this->workspace));

        $this->assertSame(0, $link->refresh()->uses);
    }

    public function test_the_link_never_grants_admin(): void
    {
        $link = $this->activeLink();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post(route('workspace.join.store', $link->token));

        $this->assertNotSame(UserRole::ADMIN, $this->workspace->roleFor($outsider->refresh()));
        $this->assertNotSame(UserRole::OWNER, $this->workspace->roleFor($outsider->refresh()));
    }

    public function test_a_link_from_another_workspace_only_joins_that_workspace(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $link = WorkspaceInviteLink::factory()->create([
            'workspace_id' => $other->id,
            'created_by' => $other->owner_id,
        ]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post(route('workspace.join.store', $link->token));

        $this->assertTrue($other->fresh()->hasMember($outsider));
        $this->assertFalse($this->workspace->fresh()->hasMember($outsider));
    }

    public function test_generating_and_joining_are_audited(): void
    {
        $this->actingAs($this->owner)->post(route('workspace.invite-link.store', $this->workspace));

        $link = WorkspaceInviteLink::query()->firstOrFail();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post(route('workspace.join.store', $link->token));

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'action' => 'invite_link.generated',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'user_id' => $outsider->id,
            'action' => 'invite_link.joined',
        ]);
    }

    public function test_revoking_is_audited(): void
    {
        $this->activeLink();

        $this->actingAs($this->owner)->delete(route('workspace.invite-link.destroy', $this->workspace));

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'action' => 'invite_link.revoked',
        ]);
    }

    public function test_the_invite_page_exposes_the_active_link_to_an_admin(): void
    {
        $link = $this->activeLink();

        $this->actingAs($this->owner)
            ->get(route('workspace.invitations.create', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canManageInviteLink', true)
                ->where('inviteLink.url', route('workspace.join.show', ['token' => $link->token]))
                ->where('inviteLink.uses', 0));
    }

    public function test_the_invite_page_reports_no_link_when_none_is_active(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspace.invitations.create', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('inviteLink', null));
    }
}
