<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardWidgetsTest extends TestCase
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
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Apollo']);
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user;
    }

    private function upcomingMeeting(Project $project, string $title = 'Sprint planning'): Meeting
    {
        return Meeting::factory()->forProject($project)->createdBy($this->owner)->create([
            'title' => $title,
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
            'meeting_link' => 'https://meet.example.com/abc',
        ]);
    }

    private function pastMeeting(Project $project, string $title = 'Retro'): Meeting
    {
        return Meeting::factory()->forProject($project)->createdBy($this->owner)->create([
            'title' => $title,
            'scheduled_at' => now()->subDays(2),
            'duration_minutes' => 30,
            'meeting_link' => null,
        ]);
    }

    public function test_an_upcoming_meeting_appears_with_its_project_and_join_link(): void
    {
        $meeting = $this->upcomingMeeting($this->project);

        $this->actingAs($this->owner)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('upcomingMeetings', 1)
                ->where('upcomingMeetings.0.id', $meeting->id)
                ->where('upcomingMeetings.0.title', 'Sprint planning')
                ->where('upcomingMeetings.0.project_name', 'Apollo')
                ->where('upcomingMeetings.0.join_url', 'https://meet.example.com/abc')
                ->where('upcomingMeetings.0.is_past', false)
                ->has('pastMeetings', 0));
    }

    public function test_a_past_meeting_is_separated_from_upcoming_meetings(): void
    {
        $this->upcomingMeeting($this->project);
        $past = $this->pastMeeting($this->project);

        $this->actingAs($this->owner)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('upcomingMeetings', 1)
                ->has('pastMeetings', 1)
                ->where('pastMeetings.0.id', $past->id)
                ->where('pastMeetings.0.is_past', true)
                ->where('pastMeetings.0.join_url', null));
    }

    public function test_an_invalid_meeting_link_is_not_exposed_as_a_join_url(): void
    {
        $this->upcomingMeeting($this->project)->update(['meeting_link' => 'javascript:alert(1)']);

        $this->actingAs($this->owner)
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('upcomingMeetings.0.join_url', null));
    }

    public function test_meetings_from_an_inaccessible_project_do_not_appear(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $assigned = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Assigned']);
        $assigned->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->upcomingMeeting($assigned, 'Visible meeting');
        $this->upcomingMeeting($this->project, 'Hidden meeting');

        $this->actingAs($member)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('upcomingMeetings', 1)
                ->where('upcomingMeetings.0.title', 'Visible meeting'))
            ->assertDontSee('Hidden meeting');
    }

    public function test_meetings_from_another_workspace_never_appear(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $other->users()->attach($this->owner->id, ['role' => UserRole::OWNER->value]);
        $otherProject = Project::factory()->forWorkspace($other)->create();

        $this->upcomingMeeting($otherProject, 'Other workspace meeting');

        $this->actingAs($this->owner)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('upcomingMeetings', 0))
            ->assertDontSee('Other workspace meeting');
    }

    public function test_the_meeting_lists_are_bounded_by_the_configured_limit(): void
    {
        config(['workspace.dashboard_meeting_limit' => 2]);

        for ($i = 0; $i < 4; $i++) {
            $this->upcomingMeeting($this->project, "Meeting {$i}");
        }

        $this->actingAs($this->owner)
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page->has('upcomingMeetings', 2));
    }

    public function test_task_completion_numbers_are_correct(): void
    {
        $column = $this->project->boardColumns()->where('is_done', false)->firstOrFail();
        $doneColumn = $this->project->boardColumns()->where('is_done', true)->firstOrFail();

        Task::factory()->count(3)->forProject($this->project)->forColumn($column)->create();
        Task::factory()->count(1)->forProject($this->project)->forColumn($doneColumn)->create();

        $this->actingAs($this->owner)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('taskProgress.total', 4)
                ->where('taskProgress.completed', 1)
                ->where('taskProgress.open', 3)
                ->where('taskProgress.completion_percentage', 25)
                ->has('taskProgress.columns', 2));
    }

    public function test_the_zero_task_state_reports_zeroes_rather_than_failing(): void
    {
        $this->actingAs($this->owner)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('taskProgress.total', 0)
                ->where('taskProgress.completed', 0)
                ->where('taskProgress.completion_percentage', 0)
                ->has('taskProgress.columns', 0));
    }

    public function test_the_project_summary_reports_per_project_task_counts(): void
    {
        $doneColumn = $this->project->boardColumns()->where('is_done', true)->firstOrFail();
        $todoColumn = $this->project->boardColumns()->where('is_done', false)->firstOrFail();

        Task::factory()->count(1)->forProject($this->project)->forColumn($doneColumn)->create();
        Task::factory()->count(1)->forProject($this->project)->forColumn($todoColumn)->create();

        $this->actingAs($this->owner)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('projects', 1)
                ->where('projects.0.name', 'Apollo')
                ->where('projects.0.total_tasks', 2)
                ->where('projects.0.completed_tasks', 1)
                ->where('projects.0.completion_percentage', 50));
    }

    public function test_an_admin_sees_every_workspace_project(): void
    {
        $admin = $this->memberOf(UserRole::ADMIN);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Second']);

        $this->actingAs($admin)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('projects', 2));
    }

    public function test_a_project_manager_sees_only_their_assigned_project(): void
    {
        $manager = $this->memberOf(UserRole::MEMBER);
        $assigned = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Managed']);
        $assigned->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($manager)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('projects', 1)
                ->where('projects.0.name', 'Managed'));
    }

    public function test_a_project_member_sees_only_their_assigned_project(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $assigned = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Joined']);
        $assigned->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($member)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('projects', 1)
                ->where('projects.0.name', 'Joined'));
    }

    public function test_an_unassigned_workspace_member_sees_no_project_or_meeting_data(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $column = $this->project->boardColumns()->where('is_done', false)->firstOrFail();
        Task::factory()->count(2)->forProject($this->project)->forColumn($column)->create();
        $this->upcomingMeeting($this->project, 'Private meeting');

        $this->actingAs($outsider)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('projects', 0)
                ->has('upcomingMeetings', 0)
                ->has('pastMeetings', 0)
                ->where('taskProgress.total', 0))
            ->assertDontSee('Private meeting');
    }

    public function test_task_progress_excludes_tasks_from_inaccessible_projects(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $assigned = Project::factory()->forWorkspace($this->workspace)->create();
        $assigned->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        Task::factory()->count(2)
            ->forProject($assigned)
            ->forColumn($assigned->boardColumns()->where('is_done', false)->firstOrFail())
            ->create();

        Task::factory()->count(5)
            ->forProject($this->project)
            ->forColumn($this->project->boardColumns()->where('is_done', false)->firstOrFail())
            ->create();

        $this->actingAs($member)
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('taskProgress.total', 2));
    }
}
