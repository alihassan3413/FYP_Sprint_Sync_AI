<?php

declare(strict_types=1);

namespace Tests\Feature\Archive;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArchiveSearchTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $projectMember;

    private User $unrelatedMember;

    private Project $project;

    private BoardColumn $todoColumn;

    private BoardColumn $doneColumn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->projectMember = User::factory()->create();
        $this->workspace->users()->attach($this->projectMember->id, ['role' => UserRole::MEMBER->value]);

        $this->unrelatedMember = User::factory()->create();
        $this->workspace->users()->attach($this->unrelatedMember->id, ['role' => UserRole::MEMBER->value]);

        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Apollo']);
        $this->project->members()->attach($this->projectMember->id, ['role' => ProjectRole::MEMBER->value]);

        $this->todoColumn = BoardColumn::query()->where('project_id', $this->project->id)->where('name', 'To Do')->firstOrFail();
        $this->doneColumn = BoardColumn::query()->where('project_id', $this->project->id)->where('name', 'Done')->firstOrFail();
    }

    private function archiveRoute(array $params = []): string
    {
        return route('workspace.archive.index', array_merge(['workspace' => $this->workspace], $params));
    }

    public function test_completed_tasks_appear_in_the_archive(): void
    {
        $done = Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->create(['title' => 'Ship release notes']);
        Task::factory()->forProject($this->project)->forColumn($this->todoColumn)->create(['title' => 'Still pending']);

        $response = $this->actingAs($this->owner)->get($this->archiveRoute())->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('results.total', 1)
            ->where('results.data.0.id', "task-{$done->id}")
            ->where('results.data.0.type', 'task')
            ->where('results.data.0.title', 'Ship release notes'));
    }

    public function test_past_meetings_appear_in_the_archive(): void
    {
        $past = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->scheduledAt('2020-01-01 10:00:00')->create([
            'title' => 'Kickoff retro',
            'duration_minutes' => 30,
        ]);
        Meeting::factory()->forProject($this->project)->createdBy($this->owner)->scheduledAt(now()->addWeek()->toDateTimeString())->create([
            'title' => 'Upcoming standup',
        ]);

        $response = $this->actingAs($this->owner)->get($this->archiveRoute())->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('results.total', 1)
            ->where('results.data.0.id', "meeting-{$past->id}")
            ->where('results.data.0.type', 'meeting')
            ->where('results.data.0.title', 'Kickoff retro'));
    }

    public function test_active_and_incomplete_records_are_excluded(): void
    {
        Task::factory()->forProject($this->project)->forColumn($this->todoColumn)->create();
        Meeting::factory()->forProject($this->project)->createdBy($this->owner)->scheduledAt(now()->addDay()->toDateTimeString())->create();

        $this->actingAs($this->owner)
            ->get($this->archiveRoute())
            ->assertInertia(fn ($page) => $page->where('results.total', 0));
    }

    public function test_keyword_search_filters_by_title(): void
    {
        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->create(['title' => 'Design the landing page']);
        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->create(['title' => 'Fix billing bug']);

        $this->actingAs($this->owner)
            ->get($this->archiveRoute(['q' => 'landing']))
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 1)
                ->where('results.data.0.title', 'Design the landing page'));
    }

    public function test_project_filter_scopes_to_one_project(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Zeus']);
        $otherDone = BoardColumn::query()->where('project_id', $otherProject->id)->where('name', 'Done')->firstOrFail();

        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->create(['title' => 'Apollo task']);
        Task::factory()->forProject($otherProject)->forColumn($otherDone)->create(['title' => 'Zeus task']);

        $this->actingAs($this->owner)
            ->get($this->archiveRoute(['project_id' => $otherProject->id]))
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 1)
                ->where('results.data.0.title', 'Zeus task'));
    }

    public function test_date_range_filters_by_occurred_at(): void
    {
        $inRange = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->scheduledAt('2024-06-10 10:00:00')->create([
            'title' => 'June meeting',
            'duration_minutes' => 30,
        ]);
        Meeting::factory()->forProject($this->project)->createdBy($this->owner)->scheduledAt('2023-01-10 10:00:00')->create([
            'title' => 'Old meeting',
            'duration_minutes' => 30,
        ]);

        $this->actingAs($this->owner)
            ->get($this->archiveRoute(['from' => '2024-06-01', 'to' => '2024-06-30']))
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 1)
                ->where('results.data.0.id', "meeting-{$inRange->id}"));
    }

    public function test_assignee_filter_scopes_to_that_users_tasks(): void
    {
        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->assignedTo($this->projectMember)->create(['title' => 'Assigned task']);
        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->create(['title' => 'Unassigned task']);

        $this->actingAs($this->owner)
            ->get($this->archiveRoute(['assignee_id' => $this->projectMember->id]))
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 1)
                ->where('results.data.0.title', 'Assigned task')
                ->where('results.data.0.assignee_name', $this->projectMember->name));
    }

    public function test_an_unassigned_workspace_member_is_denied_the_archive(): void
    {
        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->create(['title' => 'Private task']);

        $this->actingAs($this->unrelatedMember)
            ->get($this->archiveRoute())
            ->assertForbidden();
    }

    public function test_a_project_member_only_sees_their_own_projects_archive(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Zeus']);
        $otherDone = BoardColumn::query()->where('project_id', $otherProject->id)->where('name', 'Done')->firstOrFail();

        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->create(['title' => 'Apollo task']);
        Task::factory()->forProject($otherProject)->forColumn($otherDone)->create(['title' => 'Zeus task']);

        $this->actingAs($this->projectMember)
            ->get($this->archiveRoute())
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 1)
                ->where('results.data.0.title', 'Apollo task'));
    }

    public function test_requesting_an_inaccessible_project_id_returns_no_results(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Zeus']);
        $otherDone = BoardColumn::query()->where('project_id', $otherProject->id)->where('name', 'Done')->firstOrFail();
        Task::factory()->forProject($otherProject)->forColumn($otherDone)->create(['title' => 'Zeus task']);

        $this->actingAs($this->projectMember)
            ->get($this->archiveRoute(['project_id' => $otherProject->id]))
            ->assertInertia(fn ($page) => $page->where('results.total', 0));
    }

    public function test_cross_workspace_data_never_appears(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();
        $otherProject = Project::factory()->forWorkspace($otherWorkspace)->create();
        $otherDone = BoardColumn::query()->where('project_id', $otherProject->id)->where('name', 'Done')->firstOrFail();
        Task::factory()->forProject($otherProject)->forColumn($otherDone)->create(['title' => 'Other workspace task']);

        Task::factory()->forProject($this->project)->forColumn($this->doneColumn)->create(['title' => 'My workspace task']);

        $this->actingAs($this->owner)
            ->get($this->archiveRoute())
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 1)
                ->where('results.data.0.title', 'My workspace task'));
    }

    public function test_a_non_member_of_the_other_workspace_cannot_load_its_archive_at_all(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();

        $this->actingAs($this->owner)
            ->get(route('workspace.archive.index', ['workspace' => $otherWorkspace]))
            ->assertNotFound();
    }

    public function test_pagination_splits_results_across_pages(): void
    {
        Task::factory(25)->forProject($this->project)->forColumn($this->doneColumn)->create();

        $this->actingAs($this->owner)
            ->get($this->archiveRoute())
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 25)
                ->where('results.current_page', 1)
                ->has('results.data', 20));

        $this->actingAs($this->owner)
            ->get($this->archiveRoute(['page' => 2]))
            ->assertInertia(fn ($page) => $page
                ->where('results.current_page', 2)
                ->has('results.data', 5));
    }
}
