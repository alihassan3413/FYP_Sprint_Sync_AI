<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectMemberTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->admin = User::factory()->create();
        $this->workspace->users()->attach($this->admin->id, ['role' => UserRole::ADMIN->value]);

        $this->member = User::factory()->create();
        $this->workspace->users()->attach($this->member->id, ['role' => UserRole::MEMBER->value]);

        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
    }

    private function memberRoute(string $name, ?User $member = null): string
    {
        $params = ['workspace' => $this->workspace, 'project' => $this->project];

        if ($member !== null) {
            $params['member'] = $member;
        }

        return route($name, $params);
    }

    public function test_an_admin_can_assign_a_workspace_member_to_a_project(): void
    {
        $this->actingAs($this->admin)
            ->post($this->memberRoute('workspace.projects.members.store'), [
                'user_id' => $this->member->id,
                'role' => ProjectRole::MEMBER->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('project_users', [
            'project_id' => $this->project->id,
            'user_id' => $this->member->id,
            'role' => ProjectRole::MEMBER->value,
        ]);
    }

    public function test_an_admin_can_assign_a_manager_to_a_project(): void
    {
        $this->actingAs($this->owner)
            ->post($this->memberRoute('workspace.projects.members.store'), [
                'user_id' => $this->member->id,
                'role' => ProjectRole::MANAGER->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_users', [
            'project_id' => $this->project->id,
            'user_id' => $this->member->id,
            'role' => ProjectRole::MANAGER->value,
        ]);
    }

    public function test_a_project_manager_can_assign_members_to_their_project(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->post($this->memberRoute('workspace.projects.members.store'), [
                'user_id' => $other->id,
                'role' => ProjectRole::MEMBER->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_users', [
            'project_id' => $this->project->id,
            'user_id' => $other->id,
        ]);
    }

    public function test_a_project_member_cannot_assign_other_members(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->post($this->memberRoute('workspace.projects.members.store'), [
                'user_id' => $other->id,
                'role' => ProjectRole::MEMBER->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('project_users', [
            'project_id' => $this->project->id,
            'user_id' => $other->id,
        ]);
    }

    public function test_an_unassigned_member_cannot_assign_other_members(): void
    {
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->post($this->memberRoute('workspace.projects.members.store'), [
                'user_id' => $other->id,
                'role' => ProjectRole::MEMBER->value,
            ])
            ->assertForbidden();
    }

    public function test_a_member_cannot_be_assigned_to_the_same_project_twice(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->owner)
            ->post($this->memberRoute('workspace.projects.members.store'), [
                'user_id' => $this->member->id,
                'role' => ProjectRole::MANAGER->value,
            ])
            ->assertSessionHasErrors('user_id');
    }

    public function test_a_user_outside_the_workspace_cannot_be_assigned_to_a_project(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->owner)
            ->post($this->memberRoute('workspace.projects.members.store'), [
                'user_id' => $outsider->id,
                'role' => ProjectRole::MEMBER->value,
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertDatabaseMissing('project_users', ['user_id' => $outsider->id]);
    }

    public function test_an_invalid_role_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post($this->memberRoute('workspace.projects.members.store'), [
                'user_id' => $this->member->id,
                'role' => 'owner',
            ])
            ->assertSessionHasErrors('role');
    }

    public function test_an_admin_can_change_a_project_members_role(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->owner)
            ->patch($this->memberRoute('workspace.projects.members.update', $this->member), [
                'role' => ProjectRole::MANAGER->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_users', [
            'project_id' => $this->project->id,
            'user_id' => $this->member->id,
            'role' => ProjectRole::MANAGER->value,
        ]);
    }

    public function test_a_project_member_cannot_change_roles(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($other->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->patch($this->memberRoute('workspace.projects.members.update', $other), [
                'role' => ProjectRole::MANAGER->value,
            ])
            ->assertForbidden();
    }

    public function test_updating_the_role_of_a_non_member_is_not_found(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->memberRoute('workspace.projects.members.update', $this->member), [
                'role' => ProjectRole::MANAGER->value,
            ])
            ->assertNotFound();
    }

    public function test_an_admin_can_remove_a_project_member(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->owner)
            ->delete($this->memberRoute('workspace.projects.members.destroy', $this->member))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('project_users', [
            'project_id' => $this->project->id,
            'user_id' => $this->member->id,
        ]);
    }

    public function test_a_project_manager_can_remove_a_member_from_their_project(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($other->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->delete($this->memberRoute('workspace.projects.members.destroy', $other))
            ->assertRedirect();

        $this->assertDatabaseMissing('project_users', [
            'project_id' => $this->project->id,
            'user_id' => $other->id,
        ]);
    }

    public function test_a_project_member_cannot_remove_other_members(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($other->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->delete($this->memberRoute('workspace.projects.members.destroy', $other))
            ->assertForbidden();

        $this->assertDatabaseHas('project_users', [
            'project_id' => $this->project->id,
            'user_id' => $other->id,
        ]);
    }

    public function test_removing_a_non_member_is_not_found(): void
    {
        $this->actingAs($this->owner)
            ->delete($this->memberRoute('workspace.projects.members.destroy', $this->member))
            ->assertNotFound();
    }

    public function test_an_outsider_cannot_manage_project_members(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post($this->memberRoute('workspace.projects.members.store'), [
                'user_id' => $this->member->id,
                'role' => ProjectRole::MEMBER->value,
            ])
            ->assertNotFound();
    }

    public function test_project_membership_is_isolated_per_project(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();
        $otherProject->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->owner)
            ->patch($this->memberRoute('workspace.projects.members.update', $this->member), [
                'role' => ProjectRole::MANAGER->value,
            ])
            ->assertNotFound();
    }

    public function test_a_project_from_another_workspace_cannot_have_members_managed_through_this_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $foreignProject = Project::factory()->forWorkspace($otherWorkspace)->create();

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.members.store', ['workspace' => $this->workspace, 'project' => $foreignProject]), [
                'user_id' => $this->member->id,
                'role' => ProjectRole::MEMBER->value,
            ])
            ->assertNotFound();
    }

    public function test_the_project_show_page_includes_project_members_and_permission_flag(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/show')
                ->has('projectMembers', 1)
                ->where('projectMembers.0.role', ProjectRole::MANAGER->value)
                ->where('canManageProjectMembers', true)
                ->has('workspaceMembers', 3));

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canManageProjectMembers', true));
    }

    public function test_a_plain_project_member_does_not_receive_the_workspace_roster(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canManageProjectMembers', false)
                ->where('canManageTasks', false)
                ->where('canDeleteProject', false)
                ->has('workspaceMembers', 0)
                ->has('members', 0))
            ->assertDontSee($this->admin->email);
    }

    public function test_a_project_manager_still_receives_the_assignee_picker_roster(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canManageTasks', true)
                ->has('members', 3));
    }

    public function test_a_project_manager_still_receives_the_workspace_roster(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canManageProjectMembers', true)
                ->has('workspaceMembers', 3));
    }

    public function test_a_project_manager_cannot_see_the_delete_project_affordance(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canManageProjects', true)
                ->where('canDeleteProject', false));

        $this->actingAs($this->member)
            ->delete(route('workspace.projects.destroy', [$this->workspace, $this->project]))
            ->assertForbidden();
    }

    public function test_an_admin_still_receives_the_full_project_payload(): void
    {
        $this->actingAs($this->admin)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canManageProjectMembers', true)
                ->where('canManageProjects', true)
                ->where('canDeleteProject', true)
                ->has('workspaceMembers', 3));
    }

    public function test_the_workspace_roster_never_crosses_workspace_boundaries(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $stranger = User::factory()->create();
        $other->users()->attach($stranger->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('workspaceMembers', 3))
            ->assertDontSee($stranger->email);
    }
}
