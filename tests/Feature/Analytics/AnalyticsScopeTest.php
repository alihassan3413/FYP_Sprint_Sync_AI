<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnalyticsScopeTest extends TestCase
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

    private function route(): string
    {
        return route('workspace.analytics.index', ['workspace' => $this->workspace]);
    }

    public function test_an_owner_sees_team_wide_totals_across_every_project(): void
    {
        $this->tasksIn($this->alpha, 3);
        $this->tasksIn($this->beta, 2);

        $this->actingAs($this->owner)
            ->get($this->route())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.scope', 'team')
                ->where('analytics.total_tasks', 5)
                ->where('analytics.total_projects', 2));
    }

    public function test_an_admin_sees_team_wide_totals(): void
    {
        $admin = $this->memberOf(UserRole::ADMIN);

        $this->tasksIn($this->alpha, 4);
        $this->tasksIn($this->beta, 1);

        $this->actingAs($admin)
            ->get($this->route())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.scope', 'team')
                ->where('analytics.total_tasks', 5));
    }

    public function test_a_project_manager_sees_team_totals_for_the_project_they_manage(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $this->tasksIn($this->alpha, 6);

        $this->actingAs($manager)
            ->get($this->route())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.scope', 'team')
                ->where('analytics.total_tasks', 6)
                ->where('analytics.total_projects', 1));
    }

    public function test_a_manager_of_one_project_only_sees_their_own_tasks_in_a_project_they_merely_belong_to(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);
        $this->beta->members()->attach($manager->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 4);
        $this->tasksIn($this->beta, 7);
        $this->tasksIn($this->beta, 2, $manager);

        $this->actingAs($manager)
            ->get($this->route())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.scope', 'team')
                ->where('analytics.total_tasks', 6)
                ->where('analytics.total_projects', 2));
    }

    public function test_the_per_project_summary_respects_the_mixed_scope(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);
        $this->beta->members()->attach($manager->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 4);
        $this->tasksIn($this->beta, 7);
        $this->tasksIn($this->beta, 2, $manager);

        $this->actingAs($manager)
            ->get($this->route())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.projects.0.name', 'Alpha')
                ->where('analytics.projects.0.total_tasks', 4)
                ->where('analytics.projects.1.name', 'Beta')
                ->where('analytics.projects.1.total_tasks', 2));
    }

    public function test_a_project_member_only_sees_their_own_assigned_tasks(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 3, $member);
        $this->tasksIn($this->alpha, 5);

        $this->actingAs($member)
            ->get($this->route())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.scope', 'personal')
                ->where('analytics.total_tasks', 3));
    }

    public function test_another_users_tasks_are_excluded_from_personal_analytics(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $colleague = $this->memberOf(UserRole::MEMBER);

        $this->alpha->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);
        $this->alpha->members()->attach($colleague->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 2, $member);
        $this->tasksIn($this->alpha, 9, $colleague);

        $this->actingAs($member)
            ->get($this->route())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.total_tasks', 2)
                ->where('analytics.tasks_by_assignee', fn ($bars) => count($bars) === 1
                    && $bars[0]['assignee_id'] === $member->id));
    }

    public function test_personal_completion_and_overdue_counts_only_include_own_tasks(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $done = BoardColumn::query()->where('project_id', $this->alpha->id)->where('name', 'Done')->firstOrFail();

        Task::factory()->forProject($this->alpha)->forColumn($done)->assignedTo($member)->count(1)->create();
        Task::factory()->forProject($this->alpha)->forColumn($this->todo($this->alpha))->assignedTo($member)
            ->withDueDate(now()->subWeek()->toDateString())->count(1)->create();

        Task::factory()->forProject($this->alpha)->forColumn($done)->count(4)->create();
        Task::factory()->forProject($this->alpha)->forColumn($this->todo($this->alpha))
            ->withDueDate(now()->subWeek()->toDateString())->count(6)->create();

        $this->actingAs($member)
            ->get($this->route())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.total_tasks', 2)
                ->where('analytics.completed_tasks', 1)
                ->where('analytics.open_tasks', 1)
                ->where('analytics.overdue_tasks', 1)
                ->where('analytics.task_completion_percentage', 50));
    }

    public function test_a_member_with_no_assigned_tasks_sees_a_zero_state_not_team_data(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 8);

        $this->actingAs($member)
            ->get($this->route())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.scope', 'personal')
                ->where('analytics.total_tasks', 0)
                ->where('analytics.completed_tasks', 0)
                ->where('analytics.overdue_tasks', 0)
                ->where('analytics.task_completion_percentage', 0)
                ->where('analytics.tasks_by_column', [])
                ->where('analytics.projects.0.total_tasks', 0));
    }

    public function test_an_unassigned_workspace_member_is_denied_analytics(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $this->tasksIn($this->alpha, 5);

        $this->actingAs($outsider)
            ->get($this->route())
            ->assertForbidden();
    }

    public function test_managed_projects_in_another_workspace_never_leak(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $manager = $this->memberOf(UserRole::MEMBER);
        $other->users()->attach($manager->id, ['role' => UserRole::MEMBER->value]);

        $foreign = Project::factory()->forWorkspace($other)->create();
        $foreign->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);
        $this->tasksIn($foreign, 9);

        $this->alpha->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);
        $this->tasksIn($this->alpha, 2);

        $this->actingAs($manager)
            ->get($this->route())
            ->assertInertia(fn ($page) => $page
                ->where('analytics.total_tasks', 2)
                ->where('analytics.total_projects', 1));
    }

    public function test_the_project_filter_cannot_widen_a_personal_scope(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->alpha->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->tasksIn($this->alpha, 6);
        $this->tasksIn($this->alpha, 1, $member);

        $this->actingAs($member)
            ->get(route('workspace.analytics.index', [
                'workspace' => $this->workspace,
                'project_id' => $this->alpha->id,
            ]))
            ->assertInertia(fn ($page) => $page->where('analytics.total_tasks', 1));
    }
}
