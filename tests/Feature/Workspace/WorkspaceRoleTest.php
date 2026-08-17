<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceRoleTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
    }

    public function test_the_index_page_reports_real_system_role_counts_and_the_manage_flag(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $this->workspace->users()->attach($admin->id, ['role' => UserRole::ADMIN->value]);
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        WorkspaceRole::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($this->owner)
            ->get(route('workspace.roles.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('workspace/settings/RoleManagement')
                ->where('canManageRoles', true)
                ->has('roles', 1)
                ->has('systemRoles', 3)
                ->where('systemRoles.0.value', UserRole::OWNER->value)
                ->where('systemRoles.0.member_count', 1)
                ->where('systemRoles.1.value', UserRole::ADMIN->value)
                ->where('systemRoles.1.member_count', 1)
                ->where('systemRoles.2.value', UserRole::MEMBER->value)
                ->where('systemRoles.2.member_count', 1));
    }

    public function test_a_plain_member_cannot_manage_roles(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        $role = WorkspaceRole::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($member)
            ->get(route('workspace.roles.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManageRoles', false));

        $this->actingAs($member)
            ->post(route('workspace.roles.store', $this->workspace), ['name' => 'Sneaky'])
            ->assertForbidden();

        $this->actingAs($member)
            ->put(route('workspace.roles.update', [$this->workspace, $role]), ['name' => 'Sneaky'])
            ->assertForbidden();

        $this->actingAs($member)
            ->delete(route('workspace.roles.destroy', [$this->workspace, $role]))
            ->assertForbidden();
    }

    public function test_an_admin_can_create_a_role_with_permissions(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.roles.store', $this->workspace), [
                'name' => 'Designer',
                'permissions' => ['projects.view' => true, 'projects.create' => true],
            ])
            ->assertRedirect();

        $role = $this->workspace->roles()->firstOrFail();

        $this->assertSame('designer', $role->slug);
        $this->assertTrue($role->grants('projects.view'));
        $this->assertFalse($role->grants('billing.manage'));
    }

    public function test_unknown_permissions_are_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.roles.store', $this->workspace), [
                'name' => 'Hacker',
                'permissions' => ['system.root' => true],
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertSame(0, $this->workspace->roles()->count());
    }

    public function test_role_names_cannot_collide_within_a_workspace(): void
    {
        WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Designer',
            'slug' => 'designer',
        ]);

        $this->actingAs($this->owner)
            ->post(route('workspace.roles.store', $this->workspace), ['name' => 'Designer'])
            ->assertSessionHasErrors('name');
    }

    public function test_built_in_role_names_are_reserved(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.roles.store', $this->workspace), ['name' => 'Owner'])
            ->assertSessionHasErrors('name');
    }

    public function test_the_same_role_name_can_exist_in_two_workspaces(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        WorkspaceRole::factory()->create(['workspace_id' => $other->id, 'name' => 'Designer', 'slug' => 'designer']);

        $this->actingAs($this->owner)
            ->post(route('workspace.roles.store', $this->workspace), ['name' => 'Designer'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->workspace->roles()->count());
    }

    public function test_an_admin_can_update_a_role(): void
    {
        $role = WorkspaceRole::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($this->owner)
            ->put(route('workspace.roles.update', [$this->workspace, $role]), [
                'name' => 'Renamed',
                'permissions' => ['billing.view' => true],
            ])
            ->assertRedirect();

        $role->refresh();

        $this->assertSame('Renamed', $role->name);
        $this->assertTrue($role->grants('billing.view'));
    }

    public function test_an_admin_can_delete_a_role_and_members_are_unassigned(): void
    {
        $role = WorkspaceRole::factory()->create(['workspace_id' => $this->workspace->id]);
        $member = User::factory()->create();

        $this->workspace->users()->attach($member->id, [
            'role' => UserRole::MEMBER->value,
            'workspace_role_id' => $role->id,
        ]);

        $this->actingAs($this->owner)
            ->delete(route('workspace.roles.destroy', [$this->workspace, $role]))
            ->assertRedirect();

        $this->assertDatabaseMissing('workspace_roles', ['id' => $role->id]);
        $this->assertDatabaseHas('workspace_users', [
            'user_id' => $member->id,
            'workspace_id' => $this->workspace->id,
            'workspace_role_id' => null,
        ]);
    }

    public function test_a_role_from_another_workspace_cannot_be_updated(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $role = WorkspaceRole::factory()->create(['workspace_id' => $other->id]);

        $this->actingAs($this->owner)
            ->put(route('workspace.roles.update', [$this->workspace, $role]), ['name' => 'Hijacked'])
            ->assertNotFound();
    }
}
