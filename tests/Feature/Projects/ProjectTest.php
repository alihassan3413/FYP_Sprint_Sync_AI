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

final class ProjectTest extends TestCase
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

    public function test_an_owner_lists_every_project_in_the_workspace(): void
    {
        Project::factory()->count(3)->forWorkspace($this->workspace)->create();

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/index')
                ->has('projects', 3)
                ->where('canManageProjects', true));
    }

    public function test_a_member_only_sees_projects_they_are_assigned_to(): void
    {
        $assigned = Project::factory()->forWorkspace($this->workspace)->create();
        Project::factory()->count(2)->forWorkspace($this->workspace)->create();

        $assigned->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->get(route('workspace.projects.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/index')
                ->has('projects', 1)
                ->where('projects.0.id', $assigned->id)
                ->where('canManageProjects', false));
    }

    public function test_an_unassigned_member_sees_no_projects(): void
    {
        Project::factory()->count(3)->forWorkspace($this->workspace)->create();

        $this->actingAs($this->member)
            ->get(route('workspace.projects.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/index')
                ->has('projects', 0));
    }

    public function test_an_owner_can_create_a_project_with_a_description(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.projects.store', $this->workspace), [
                'name' => 'Mobile Redesign',
                'description' => 'Rebuild the mobile app onboarding flow.',
            ])
            ->assertRedirect(route('workspace.projects.index', $this->workspace))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('projects', [
            'workspace_id' => $this->workspace->id,
            'name' => 'Mobile Redesign',
            'description' => 'Rebuild the mobile app onboarding flow.',
        ]);
    }

    public function test_the_description_is_optional(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.projects.store', $this->workspace), ['name' => 'Backend Migration'])
            ->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'workspace_id' => $this->workspace->id,
            'name' => 'Backend Migration',
            'description' => null,
        ]);
    }

    public function test_the_name_is_required(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.projects.store', $this->workspace), ['description' => 'No name given.'])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $this->workspace->projects()->count());
    }

    public function test_multiple_projects_can_exist_in_the_same_workspace(): void
    {
        Project::factory()->count(2)->forWorkspace($this->workspace)->create();

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.store', $this->workspace), ['name' => 'Third Project'])
            ->assertRedirect();

        $this->assertSame(3, $this->workspace->projects()->count());
    }

    public function test_a_member_cannot_create_a_project(): void
    {
        $this->actingAs($this->member)
            ->post(route('workspace.projects.store', $this->workspace), ['name' => 'Not Allowed'])
            ->assertForbidden();

        $this->assertSame(0, $this->workspace->projects()->count());
    }

    public function test_an_assigned_member_can_view_a_single_project(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create();
        $project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/show')
                ->where('project.id', $project->id)
                ->where('canManageProjects', false));
    }

    public function test_an_unassigned_member_cannot_view_a_project(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $project]))
            ->assertForbidden();
    }

    public function test_an_owner_can_view_any_project_without_being_assigned(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', [$this->workspace, $project]))
            ->assertOk();
    }

    public function test_a_project_manager_can_update_their_project_without_being_a_workspace_admin(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Old Name']);
        $project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->member)
            ->put(route('workspace.projects.update', [$this->workspace, $project]), [
                'name' => 'New Name',
                'description' => 'Updated by manager.',
            ])
            ->assertRedirect();

        $this->assertSame('New Name', $project->fresh()->name);
    }

    public function test_a_project_member_cannot_update_their_project(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Untouchable']);
        $project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->put(route('workspace.projects.update', [$this->workspace, $project]), ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Untouchable', $project->fresh()->name);
    }

    public function test_a_project_manager_cannot_delete_their_project(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create();
        $project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->member)
            ->delete(route('workspace.projects.destroy', [$this->workspace, $project]))
            ->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_an_owner_can_update_a_project(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Old Name']);

        $this->actingAs($this->owner)
            ->put(route('workspace.projects.update', [$this->workspace, $project]), [
                'name' => 'New Name',
                'description' => 'Updated description.',
            ])
            ->assertRedirect();

        $project->refresh();

        $this->assertSame('New Name', $project->name);
        $this->assertSame('Updated description.', $project->description);
    }

    public function test_a_member_cannot_update_a_project(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Untouchable']);

        $this->actingAs($this->member)
            ->put(route('workspace.projects.update', [$this->workspace, $project]), ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Untouchable', $project->fresh()->name);
    }

    public function test_an_owner_can_delete_a_project(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->actingAs($this->owner)
            ->delete(route('workspace.projects.destroy', [$this->workspace, $project]))
            ->assertRedirect(route('workspace.projects.index', $this->workspace))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_a_member_cannot_delete_a_project(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->actingAs($this->member)
            ->delete(route('workspace.projects.destroy', [$this->workspace, $project]))
            ->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_an_outsider_cannot_list_projects(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('workspace.projects.index', $this->workspace))
            ->assertNotFound();
    }

    public function test_an_outsider_cannot_view_a_project(): void
    {
        $outsider = User::factory()->create();
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->actingAs($outsider)
            ->get(route('workspace.projects.show', [$this->workspace, $project]))
            ->assertNotFound();
    }

    public function test_a_project_from_another_workspace_cannot_be_viewed_through_this_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $foreignProject = Project::factory()->forWorkspace($otherWorkspace)->create();

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', [$this->workspace, $foreignProject]))
            ->assertNotFound();
    }

    public function test_a_project_from_another_workspace_cannot_be_updated_through_this_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $foreignProject = Project::factory()->forWorkspace($otherWorkspace)->create(['name' => 'Foreign']);

        $this->actingAs($this->owner)
            ->put(route('workspace.projects.update', [$this->workspace, $foreignProject]), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Foreign', $foreignProject->fresh()->name);
    }

    public function test_a_project_from_another_workspace_cannot_be_deleted_through_this_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $foreignProject = Project::factory()->forWorkspace($otherWorkspace)->create();

        $this->actingAs($this->owner)
            ->delete(route('workspace.projects.destroy', [$this->workspace, $foreignProject]))
            ->assertNotFound();

        $this->assertDatabaseHas('projects', ['id' => $foreignProject->id]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('workspace.projects.index', $this->workspace))
            ->assertRedirect(route('login'));
    }

    public function test_deleting_a_workspace_deletes_its_projects(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->workspace->delete();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }
}
