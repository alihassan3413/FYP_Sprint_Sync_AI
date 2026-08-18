<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardScopeTest extends TestCase
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
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->alpha = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Alpha']);
        $this->beta = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Beta']);
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user;
    }

    private function todo(Project $project): BoardColumn
    {
        return BoardColumn::query()->where('project_id', $project->id)->where('name', 'To Do')->firstOrFail();
    }

    private function tasksIn(Project $project, int $count, ?User $assignee = null): void
    {
        $factory = Task::factory()->forProject($project)->forColumn($this->todo($project))->count($count);

        if ($assignee !== null) {
            $factory = $factory->assignedTo($assignee);
        }

        $factory->create();
    }

    private function dashboard(User $user)
    {
        return $this->actingAs($user)->get(route('dashboard', $this->workspace));
    }

    public function test_an_owner_gets_a_team_dashboard_with_workspace_wide_totals(): void
    {
        $this->tasksIn($this->alpha, 3);
        $this->tasksIn($this->beta, 2);

        $this->dashboard($this->owner)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'team')
                ->where('taskProgress.total', 5)
                ->where('capabilities.canManageWorkspace', true)
                ->where('capabilities.canInviteMembers', true)
                ->where('capabilities.canCreateProjects', true));
    }

    public function test_an_admin_gets_a_team_dashboard(): void
    {
        $admin = $this->memberOf(UserRole::ADMIN);

        $this->tasksIn($this->alpha, 4);

        $this->dashboard($admin)
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'team')
                ->where('taskProgress.total', 4)
                ->where('capabilities.canManageWorkspace', true));
    }

    public function test_a_manager_gets_team_scope_in_managed_projects_and_personal_scope_elsewhere(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);
        $this->beta->members()->attach($manager->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 4);
        $this->tasksIn($this->beta, 7);
        $this->tasksIn($this->beta, 2, $manager);

        $this->dashboard($manager)
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'team')
                ->where('taskProgress.total', 6)
                ->where('projects.0.name', 'Alpha')
                ->where('projects.0.total_tasks', 4)
                ->where('projects.1.name', 'Beta')
                ->where('projects.1.total_tasks', 2));
    }

    public function test_a_plain_member_gets_a_personal_dashboard(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 3, $member);
        $this->tasksIn($this->alpha, 6);

        $this->dashboard($member)
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'personal')
                ->where('taskProgress.total', 3)
                ->where('capabilities.canManageWorkspace', false)
                ->where('capabilities.canInviteMembers', false)
                ->where('capabilities.canCreateProjects', false));
    }

    public function test_another_users_tasks_are_excluded_from_a_members_dashboard(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $colleague = $this->memberOf(UserRole::MEMBER);

        $this->alpha->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);
        $this->alpha->members()->attach($colleague->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 2, $member);
        $this->tasksIn($this->alpha, 9, $colleague);

        $this->dashboard($member)
            ->assertInertia(fn ($page) => $page
                ->where('taskProgress.total', 2)
                ->where('projects.0.total_tasks', 2));
    }

    public function test_a_member_with_no_assignments_sees_a_zero_state_not_team_data(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 8);

        $this->dashboard($member)
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'personal')
                ->where('taskProgress.total', 0)
                ->where('taskProgress.completed', 0)
                ->where('taskProgress.overdue', 0)
                ->where('taskProgress.completion_percentage', 0)
                ->where('taskProgress.columns', []));
    }

    public function test_an_unassigned_workspace_member_sees_no_project_data(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $this->tasksIn($this->alpha, 5);

        $this->dashboard($outsider)
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'personal')
                ->where('taskProgress.total', 0)
                ->where('projects', []));
    }

    public function test_cross_workspace_tasks_never_reach_the_dashboard(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $other->users()->attach($this->owner->id, ['role' => UserRole::OWNER->value]);

        $foreign = Project::factory()->forWorkspace($other)->create();
        $this->tasksIn($foreign, 9);
        $this->tasksIn($this->alpha, 2);

        $this->dashboard($this->owner)
            ->assertInertia(fn ($page) => $page
                ->where('taskProgress.total', 2)
                ->has('projects', 2));
    }

    public function test_a_manager_of_a_project_in_another_workspace_gets_personal_scope_here(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $manager = $this->memberOf(UserRole::MEMBER);
        $other->users()->attach($manager->id, ['role' => UserRole::MEMBER->value]);

        $foreign = Project::factory()->forWorkspace($other)->create();
        $foreign->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $this->alpha->members()->attach($manager->id, ['role' => ProjectRole::MEMBER->value]);
        $this->tasksIn($this->alpha, 4);

        $this->dashboard($manager)
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'personal')
                ->where('taskProgress.total', 0));
    }
}
