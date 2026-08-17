<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NavigationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user;
    }

    private function projectIn(Workspace $workspace): Project
    {
        return Project::factory()->create(['workspace_id' => $workspace->id]);
    }

    public function test_an_owner_sees_every_navigation_entry(): void
    {
        $this->projectIn($this->workspace);

        $this->actingAs($this->owner)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('navigation.projects', true)
                ->where('navigation.team', true)
                ->where('navigation.analytics', true)
                ->where('navigation.archive', true)
                ->where('navigation.audit', true)
                ->where('navigation.workspaceSettings', true));
    }

    public function test_an_admin_sees_projects_navigation_even_with_no_projects_yet(): void
    {
        $admin = $this->memberOf(UserRole::ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('navigation.projects', true)
                ->where('navigation.analytics', false)
                ->where('navigation.archive', false)
                ->where('navigation.audit', true)
                ->where('navigation.workspaceSettings', true));
    }

    public function test_a_project_manager_sees_project_scoped_navigation_and_the_audit_log(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $project = $this->projectIn($this->workspace);
        $project->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($manager)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('navigation.projects', true)
                ->where('navigation.analytics', true)
                ->where('navigation.archive', true)
                ->where('navigation.audit', true)
                ->where('navigation.workspaceSettings', true));
    }

    public function test_a_plain_project_member_sees_project_navigation_but_no_audit_or_settings(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $project = $this->projectIn($this->workspace);
        $project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($member)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('navigation.projects', true)
                ->where('navigation.team', true)
                ->where('navigation.analytics', true)
                ->where('navigation.archive', true)
                ->where('navigation.audit', false)
                ->where('navigation.workspaceSettings', false));
    }

    public function test_an_unassigned_workspace_member_gets_no_project_scoped_navigation(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);
        $this->projectIn($this->workspace);

        $this->actingAs($outsider)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('navigation.projects', false)
                ->where('navigation.analytics', false)
                ->where('navigation.archive', false)
                ->where('navigation.audit', false)
                ->where('navigation.workspaceSettings', false)
                ->where('navigation.team', true));
    }

    public function test_navigation_audit_visibility_matches_the_audit_log_policy(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $project = $this->projectIn($this->workspace);
        $project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($member)
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('navigation.audit', false));

        $this->actingAs($member)
            ->get(route('workspace.audit.index', $this->workspace))
            ->assertForbidden();

        $project->members()->updateExistingPivot($member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($member)
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('navigation.audit', true));

        $this->actingAs($member)
            ->get(route('workspace.audit.index', $this->workspace))
            ->assertOk();
    }

    public function test_navigation_only_reflects_projects_in_the_current_workspace(): void
    {
        $user = $this->memberOf(UserRole::MEMBER);

        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $other->users()->attach($user->id, ['role' => UserRole::MEMBER->value]);

        $otherProject = $this->projectIn($other);
        $otherProject->members()->attach($user->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($user)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('navigation.projects', false)
                ->where('navigation.analytics', false)
                ->where('navigation.archive', false)
                ->where('navigation.audit', false));
    }

    public function test_navigation_is_null_for_a_guest(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('navigation', null));
    }
}
