<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Data\WorkspacePermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PermissionAwareNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Alpha']);
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    /**
     * @param  array<int, WorkspacePermission>  $permissions
     */
    private function customRoleMember(array $permissions): User
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'permissions' => WorkspacePermission::normalise(
                array_fill_keys(array_map(fn (WorkspacePermission $p) => $p->value, $permissions), true),
            ),
        ]);

        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, [
            'role' => UserRole::MEMBER->value,
            'workspace_role_id' => $role->id,
        ]);

        return $user;
    }

    private function dashboard(User $user)
    {
        return $this->actingAs($user)->get(route('dashboard', ['workspace' => $this->workspace->slug]));
    }

    public function test_the_owner_sees_every_navigation_destination(): void
    {
        $this->dashboard($this->owner)
            ->assertInertia(fn ($page) => $page
                ->where('navigation.projects', true)
                ->where('navigation.team', true)
                ->where('navigation.analytics', true)
                ->where('navigation.archive', true)
                ->where('navigation.audit', true)
                ->where('navigation.workspaceSettings', true));
    }

    public function test_a_member_without_projects_sees_a_reduced_navigation(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $this->dashboard($member)
            ->assertInertia(fn ($page) => $page
                ->where('navigation.projects', false)
                ->where('navigation.analytics', false)
                ->where('navigation.archive', false)
                ->where('navigation.audit', false)
                ->where('navigation.workspaceSettings', false));
    }

    public function test_hidden_analytics_navigation_matches_a_backend_denial(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $this->dashboard($member)
            ->assertInertia(fn ($page) => $page->where('navigation.analytics', false));

        $this->actingAs($member)
            ->get(route('workspace.analytics.index', ['workspace' => $this->workspace->slug]))
            ->assertForbidden();
    }

    public function test_hidden_archive_navigation_matches_a_backend_denial(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $this->actingAs($member)
            ->get(route('workspace.archive.index', ['workspace' => $this->workspace->slug]))
            ->assertForbidden();
    }

    public function test_hidden_audit_navigation_matches_a_backend_denial(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $this->dashboard($member)
            ->assertInertia(fn ($page) => $page->where('navigation.audit', false));

        $this->actingAs($member)
            ->get(route('workspace.audit.index', ['workspace' => $this->workspace->slug]))
            ->assertForbidden();
    }

    public function test_a_project_member_regains_analytics_and_archive_navigation(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->dashboard($member)
            ->assertInertia(fn ($page) => $page
                ->where('navigation.projects', true)
                ->where('navigation.analytics', true)
                ->where('navigation.archive', true)
                ->where('navigation.audit', false));

        $this->actingAs($member)
            ->get(route('workspace.analytics.index', ['workspace' => $this->workspace->slug]))
            ->assertOk();
    }

    public function test_a_custom_role_with_project_visibility_unlocks_navigation_and_the_routes(): void
    {
        $viewer = $this->customRoleMember([WorkspacePermission::ProjectsView]);

        $this->dashboard($viewer)
            ->assertInertia(fn ($page) => $page
                ->where('navigation.projects', true)
                ->where('navigation.analytics', true)
                ->where('navigation.archive', true));

        $this->actingAs($viewer)
            ->get(route('workspace.analytics.index', ['workspace' => $this->workspace->slug]))
            ->assertOk();
    }

    public function test_a_custom_role_without_project_visibility_stays_locked_out(): void
    {
        $viewer = $this->customRoleMember([WorkspacePermission::BillingView]);

        $this->dashboard($viewer)
            ->assertInertia(fn ($page) => $page
                ->where('navigation.projects', false)
                ->where('navigation.analytics', false));

        $this->actingAs($viewer)
            ->get(route('workspace.analytics.index', ['workspace' => $this->workspace->slug]))
            ->assertForbidden();
    }

    public function test_a_custom_role_with_member_management_unlocks_workspace_settings(): void
    {
        $manager = $this->customRoleMember([WorkspacePermission::MembersRemove]);

        $this->dashboard($manager)
            ->assertInertia(fn ($page) => $page->where('navigation.workspaceSettings', true));
    }

    public function test_a_project_manager_sees_the_audit_entry_point(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $this->dashboard($manager)
            ->assertInertia(fn ($page) => $page->where('navigation.audit', true));

        $this->actingAs($manager)
            ->get(route('workspace.audit.index', ['workspace' => $this->workspace->slug]))
            ->assertOk();
    }

    public function test_navigation_reflects_the_workspace_the_user_switched_into(): void
    {
        $user = $this->memberOf(UserRole::ADMIN);

        $second = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $second->users()->attach($user->id, ['role' => UserRole::MEMBER->value]);

        $this->dashboard($user)
            ->assertInertia(fn ($page) => $page->where('navigation.audit', true));

        $this->actingAs($user)
            ->get(route('dashboard', ['workspace' => $second->slug]))
            ->assertInertia(fn ($page) => $page->where('navigation.audit', false));
    }
}
