<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->admin = User::factory()->create();
        $this->workspace->users()->attach($this->admin->id, ['role' => UserRole::ADMIN->value]);

        $this->member = User::factory()->create();
        $this->workspace->users()->attach($this->member->id, ['role' => UserRole::MEMBER->value]);
    }

    public function test_the_settings_page_exposes_permission_flags_for_an_owner(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspace.settings', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('workspace/settings/index')
                ->where('canUpdateWorkspace', true)
                ->where('canDeleteWorkspace', true)
                ->where('canManageMembers', true)
                ->where('canInviteMembers', true));
    }

    public function test_an_admin_cannot_delete_but_can_rename(): void
    {
        $this->actingAs($this->admin)
            ->get(route('workspace.settings', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canUpdateWorkspace', true)
                ->where('canDeleteWorkspace', false));
    }

    public function test_a_member_sees_no_management_permissions(): void
    {
        $this->actingAs($this->member)
            ->get(route('workspace.settings', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canUpdateWorkspace', false)
                ->where('canDeleteWorkspace', false)
                ->where('canManageMembers', false)
                ->where('canInviteMembers', false));
    }

    public function test_an_admin_can_rename_the_workspace(): void
    {
        $this->actingAs($this->admin)
            ->put(route('workspace.update', $this->workspace), [
                'name' => 'Renamed Workspace',
                'slug' => 'renamed-workspace',
            ])
            ->assertRedirect(route('workspace.settings', ['workspace' => 'renamed-workspace']));

        $this->workspace->refresh();

        $this->assertSame('Renamed Workspace', $this->workspace->name);
        $this->assertSame('renamed-workspace', $this->workspace->slug);
    }

    public function test_a_member_cannot_rename_the_workspace(): void
    {
        $original = $this->workspace->name;

        $this->actingAs($this->member)
            ->put(route('workspace.update', $this->workspace), [
                'name' => 'Hijacked',
                'slug' => 'hijacked',
            ])
            ->assertForbidden();

        $this->assertSame($original, $this->workspace->fresh()->name);
    }

    public function test_a_slug_already_taken_by_another_workspace_is_rejected(): void
    {
        Workspace::factory()->ownedBy(User::factory()->create())->create(['slug' => 'taken-slug']);

        $this->actingAs($this->owner)
            ->put(route('workspace.update', $this->workspace), [
                'name' => 'Anything',
                'slug' => 'taken-slug',
            ])
            ->assertSessionHasErrors('slug');
    }

    public function test_keeping_the_existing_slug_is_allowed(): void
    {
        $this->actingAs($this->owner)
            ->put(route('workspace.update', $this->workspace), [
                'name' => 'Just A Rename',
                'slug' => $this->workspace->slug,
            ])
            ->assertRedirect();

        $this->assertSame('Just A Rename', $this->workspace->fresh()->name);
    }

    public function test_an_owner_can_delete_a_workspace_when_another_one_remains(): void
    {
        $fallback = Workspace::factory()->ownedBy($this->owner)->create();

        $this->actingAs($this->owner)
            ->delete(route('workspace.destroy', $this->workspace))
            ->assertRedirect(route('dashboard', ['workspace' => $fallback->slug]));

        $this->assertDatabaseMissing('workspaces', ['id' => $this->workspace->id]);
    }

    public function test_deleting_a_workspace_re_points_every_affected_members_current_workspace(): void
    {
        $fallback = Workspace::factory()->ownedBy($this->owner)->create();
        $fallback->users()->attach($this->member->id, ['role' => UserRole::MEMBER->value]);

        $this->member->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->actingAs($this->owner)
            ->delete(route('workspace.destroy', $this->workspace))
            ->assertRedirect();

        $this->assertSame($fallback->id, $this->member->fresh()->current_workspace_id);
    }

    public function test_the_only_workspace_cannot_be_deleted(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('workspace.destroy', $this->workspace))
            ->assertStatus(422);

        $this->assertDatabaseHas('workspaces', ['id' => $this->workspace->id]);
    }

    public function test_the_only_workspace_guard_is_flashed_as_an_error_for_an_inertia_request(): void
    {
        $this->actingAs($this->owner)
            ->withHeader('X-Inertia', 'true')
            ->delete(route('workspace.destroy', $this->workspace))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('workspaces', ['id' => $this->workspace->id]);
    }

    public function test_deleting_a_workspace_re_points_an_owner_who_had_no_current_workspace(): void
    {
        $fallback = Workspace::factory()->ownedBy($this->owner)->create();

        $this->owner->forceFill(['current_workspace_id' => null])->save();

        $this->actingAs($this->owner)
            ->delete(route('workspace.destroy', $this->workspace))
            ->assertRedirect(route('dashboard', ['workspace' => $fallback->slug]));

        $this->assertSame($fallback->id, $this->owner->fresh()->current_workspace_id);
    }

    public function test_an_admin_cannot_delete_the_workspace(): void
    {
        Workspace::factory()->ownedBy($this->owner)->create();

        $this->actingAs($this->admin)
            ->delete(route('workspace.destroy', $this->workspace))
            ->assertForbidden();

        $this->assertDatabaseHas('workspaces', ['id' => $this->workspace->id]);
    }

    public function test_a_member_cannot_delete_the_workspace(): void
    {
        Workspace::factory()->ownedBy($this->owner)->create();

        $this->actingAs($this->member)
            ->delete(route('workspace.destroy', $this->workspace))
            ->assertForbidden();

        $this->assertDatabaseHas('workspaces', ['id' => $this->workspace->id]);
    }
}
