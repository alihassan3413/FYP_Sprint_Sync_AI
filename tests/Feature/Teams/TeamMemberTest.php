<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
