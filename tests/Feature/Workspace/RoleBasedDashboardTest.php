<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Data\WorkspacePermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RoleBasedDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $alpha;

    private Project $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->alpha = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Alpha']);
        $this->beta = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Beta']);
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

    private function tasksIn(Project $project, int $count, ?User $assignee = null): void
    {
        $column = BoardColumn::query()->where('project_id', $project->id)->firstOrFail();

        Task::factory()
            ->forProject($project)
            ->forColumn($column)
            ->when($assignee !== null, fn ($factory) => $factory->assignedTo($assignee))
            ->count($count)
            ->create();
    }

    private function dashboard(User $user)
    {
        return $this->actingAs($user)->get(route('dashboard', ['workspace' => $this->workspace->slug]));
    }

    public function test_the_owner_gets_a_team_dashboard_with_management_widgets(): void
    {
        $this->tasksIn($this->alpha, 3);

        $this->dashboard($this->owner)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'team')
                ->where('capabilities.canManageMembers', true)
                ->where('capabilities.canManageRoles', true)
                ->where('capabilities.canInviteMembers', true)
                ->where('capabilities.canCreateProjects', true)
                ->where('capabilities.canViewAudit', true)
                ->where('taskProgress.total', 3)
                ->has('members', 1));
    }

    public function test_an_admin_gets_the_same_team_scope_as_the_owner(): void
    {
        $admin = $this->memberOf(UserRole::ADMIN);
        $this->tasksIn($this->alpha, 2);

        $this->dashboard($admin)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'team')
                ->where('capabilities.canManageMembers', true)
                ->where('taskProgress.total', 2));
    }

    public function test_a_project_manager_gets_team_scope_only_for_managed_projects(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);
        $this->beta->members()->attach($manager->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 4);
        $this->tasksIn($this->beta, 6);
        $this->tasksIn($this->beta, 1, $manager);

        $this->dashboard($manager)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'team')
                ->where('capabilities.canManageMembers', false)
                ->where('capabilities.canInviteMembers', false)
                ->where('taskProgress.total', 5)
                ->where('members', [])
                ->where('activity', []));
    }

    public function test_a_plain_member_gets_personal_scope_only(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 5);
        $this->tasksIn($this->alpha, 2, $member);

        $this->dashboard($member)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'personal')
                ->where('capabilities.canManageMembers', false)
                ->where('capabilities.canCreateProjects', false)
                ->where('capabilities.canViewAudit', false)
                ->where('taskProgress.total', 2)
                ->where('members', [])
                ->where('pendingInvitesCount', 0));
    }

    public function test_a_member_with_no_projects_sees_a_zero_state_dashboard(): void
    {
        $stranger = $this->memberOf(UserRole::MEMBER);

        $this->tasksIn($this->alpha, 4);

        $this->dashboard($stranger)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('taskProgress.total', 0)
                ->where('projects', [])
                ->where('upcomingMeetings', [])
                ->where('capabilities.canViewAnalytics', false));
    }

    public function test_a_custom_role_granting_project_visibility_widens_the_dashboard(): void
    {
        $viewer = $this->customRoleMember([WorkspacePermission::ProjectsView]);

        $this->tasksIn($this->alpha, 3);
        $this->tasksIn($this->beta, 2);

        $this->dashboard($viewer)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('taskProgress.total', 0)
                ->has('projects', 2)
                ->where('capabilities.canViewAnalytics', true));
    }

    public function test_the_same_custom_role_without_project_visibility_sees_nothing(): void
    {
        $viewer = $this->customRoleMember([WorkspacePermission::BillingView]);

        $this->tasksIn($this->alpha, 3);

        $this->dashboard($viewer)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('projects', [])
                ->where('capabilities.canViewAnalytics', false));
    }

    public function test_a_custom_role_granting_invite_permission_can_actually_invite(): void
    {
        $inviter = $this->customRoleMember([WorkspacePermission::MembersInvite]);

        $this->dashboard($inviter)
            ->assertInertia(fn ($page) => $page->where('capabilities.canInviteMembers', true));

        $this->assertTrue($inviter->can('invite', $this->workspace));
    }

    public function test_the_same_custom_role_without_invite_permission_cannot_invite(): void
    {
        $member = $this->customRoleMember([WorkspacePermission::ProjectsView]);

        $this->dashboard($member)
            ->assertInertia(fn ($page) => $page->where('capabilities.canInviteMembers', false));

        $this->assertFalse($member->can('invite', $this->workspace));
    }

    public function test_a_custom_role_granting_project_creation_is_enforced_by_the_policy(): void
    {
        $creator = $this->customRoleMember([WorkspacePermission::ProjectsCreate]);
        $plain = $this->customRoleMember([WorkspacePermission::BillingView]);

        $this->assertTrue($creator->can('create', [Project::class, $this->workspace]));
        $this->assertFalse($plain->can('create', [Project::class, $this->workspace]));
    }

    public function test_a_custom_role_granting_role_management_is_enforced_by_the_policy(): void
    {
        $manager = $this->customRoleMember([WorkspacePermission::MembersRoles]);
        $plain = $this->customRoleMember([WorkspacePermission::BillingView]);

        $this->assertTrue($manager->can('manageRoles', $this->workspace));
        $this->assertFalse($plain->can('manageRoles', $this->workspace));
    }

    public function test_a_permission_change_applies_immediately_without_logging_out(): void
    {
        $user = $this->customRoleMember([WorkspacePermission::BillingView]);

        $this->dashboard($user)
            ->assertInertia(fn ($page) => $page->where('capabilities.canInviteMembers', false));

        $role = WorkspaceRole::query()->firstOrFail();
        $role->update([
            'permissions' => WorkspacePermission::normalise([WorkspacePermission::MembersInvite->value => true]),
        ]);

        $this->dashboard($user)
            ->assertInertia(fn ($page) => $page->where('capabilities.canInviteMembers', true));
    }

    public function test_a_custom_role_never_reduces_owner_authority(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'permissions' => WorkspacePermission::normalise([]),
        ]);

        $this->workspace->users()->updateExistingPivot($this->owner->id, ['workspace_role_id' => $role->id]);
        $this->workspace->forgetResolvedMembership();

        $this->assertTrue($this->owner->can('invite', $this->workspace));
        $this->assertTrue($this->owner->can('manageRoles', $this->workspace));
        $this->assertTrue($this->owner->can('delete', $this->workspace));
    }

    public function test_a_custom_role_does_not_leak_across_workspaces(): void
    {
        $viewer = $this->customRoleMember([WorkspacePermission::ProjectsView]);

        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $otherWorkspace->users()->attach($viewer->id, ['role' => UserRole::MEMBER->value]);
        Project::factory()->forWorkspace($otherWorkspace)->create(['name' => 'Foreign']);

        $this->assertSame(2, $this->workspace->accessibleProjectsFor($viewer)->count());
        $this->assertSame(0, $otherWorkspace->accessibleProjectsFor($viewer)->count());
    }
}
