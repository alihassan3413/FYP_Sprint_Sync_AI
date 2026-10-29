<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MyWorkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $employee;

    private Workspace $workspace;

    private Project $flood;

    private Project $commercial;

    private Project $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create(['timezone' => 'UTC']);
        $this->employee = User::factory()->create(['name' => 'Usman']);
        $this->workspace->users()->attach($this->employee->id, ['role' => UserRole::MEMBER->value]);

        $this->flood = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Flood Quotes']);
        $this->commercial = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Commercial Quotes']);
        $this->secret = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Leadership']);

        foreach ([$this->flood, $this->commercial] as $project) {
            $project->members()->attach($this->employee->id, ['role' => ProjectRole::MEMBER->value]);
        }
    }

    private function task(Project $project, array $attributes = []): Task
    {
        return Task::factory()->create(['project_id' => $project->id, 'title' => 'Task '.fake()->unique()->numberBetween(1, 99999), ...$attributes]);
    }

    private function done(Project $project): int
    {
        return $project->boardColumns()->where('is_done', true)->value('id');
    }

    private function myWork(array $query = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->employee)->get(route('workspace.my-work.index', [$this->workspace, ...$query]));
    }

    private function ids(TestResponse $response): array
    {
        return collect($response->inertiaProps('tasks.data'))->pluck('id')->sort()->values()->all();
    }

    public function test_an_employee_sees_their_tasks_from_every_project_they_are_on(): void
    {
        $flood = $this->task($this->flood, ['assigned_to' => $this->employee->id, 'title' => 'NFIP quote']);
        $commercial = $this->task($this->commercial, ['assigned_to' => $this->employee->id, 'title' => 'BOP quote']);
        $this->task($this->flood, ['assigned_to' => $this->owner->id]);
        $this->task($this->flood);

        $response = $this->myWork();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('my-work/index')
            ->where('filters.scope', 'mine')
            ->where('canViewAll', false)
            ->has('tasks.data', 2)
            ->where('projects', [['id' => $this->commercial->id, 'name' => 'Commercial Quotes'], ['id' => $this->flood->id, 'name' => 'Flood Quotes']]));
        $this->assertSame(collect([$flood->id, $commercial->id])->sort()->values()->all(), $this->ids($response));

        $row = collect($response->inertiaProps('tasks.data'))->firstWhere('id', $flood->id);
        $this->assertSame(['id' => $this->flood->id, 'name' => 'Flood Quotes'], $row['project']);
        $this->assertSame(route('workspace.projects.show', ['workspace' => $this->workspace->slug, 'project' => $this->flood->id, 'task' => $flood->id]), $row['url']);
        $this->assertFalse($row['column']['is_done']);
    }

    public function test_inaccessible_projects_are_excluded_even_when_asked_for(): void
    {
        $hidden = $this->task($this->secret, ['assigned_to' => $this->employee->id]);
        $visible = $this->task($this->flood, ['assigned_to' => $this->employee->id]);

        $this->assertSame([$visible->id], $this->ids($this->myWork()));
        $this->assertSame([], $this->ids($this->myWork(['project' => $this->secret->id])), 'filtering by a project you cannot see returns nothing');
        $this->assertNotContains($hidden->id, $this->ids($this->myWork(['scope' => 'all'])));
    }

    public function test_members_cannot_switch_to_all_tasks(): void
    {
        $this->task($this->flood, ['assigned_to' => $this->owner->id]);
        $mine = $this->task($this->flood, ['assigned_to' => $this->employee->id]);

        $this->myWork(['scope' => 'all'])->assertInertia(fn (Assert $page) => $page->where('filters.scope', 'mine')->where('assignees', []));
        $this->assertSame([$mine->id], $this->ids($this->myWork(['scope' => 'all'])));
    }

    public function test_owners_and_admins_can_see_all_accessible_tasks(): void
    {
        $admin = User::factory()->create();
        $this->workspace->users()->attach($admin->id, ['role' => UserRole::ADMIN->value]);
        $a = $this->task($this->flood, ['assigned_to' => $this->employee->id]);
        $b = $this->task($this->secret, ['assigned_to' => $this->owner->id]);
        $c = $this->task($this->commercial);

        $this->assertSame([$b->id], $this->ids($this->myWork([], $this->owner)), 'still only their own by default');
        $this->assertSame([], $this->ids($this->myWork([], $admin)));

        foreach ([$this->owner, $admin] as $user) {
            $response = $this->myWork(['scope' => 'all'], $user);
            $response->assertInertia(fn (Assert $page) => $page->where('canViewAll', true)->where('filters.scope', 'all'));
            $this->assertSame(collect([$a->id, $b->id, $c->id])->sort()->values()->all(), $this->ids($response));
        }

        $this->assertSame([$a->id], $this->ids($this->myWork(['scope' => 'all', 'assignee' => (string) $this->employee->id], $this->owner)));
        $this->assertSame([$c->id], $this->ids($this->myWork(['scope' => 'all', 'assignee' => 'unassigned'], $this->owner)));
    }

    public function test_status_due_date_project_and_search_filters(): void
    {
        $me = ['assigned_to' => $this->employee->id];
        $overdue = $this->task($this->flood, [...$me, 'title' => 'Chase Travelers bind', 'due_date' => '2026-10-10']);
        $today = $this->task($this->flood, [...$me, 'title' => 'Renewal remarket', 'due_date' => '2026-10-15']);
        $week = $this->task($this->commercial, [...$me, 'title' => 'Loss runs follow-up', 'due_date' => '2026-10-20']);
        $later = $this->task($this->commercial, [...$me, 'title' => 'Annual review', 'due_date' => '2026-12-01']);
        $undated = $this->task($this->commercial, [...$me, 'title' => 'Carrier appetite list']);
        $finished = $this->task($this->flood, [...$me, 'title' => 'Done flood quote', 'due_date' => '2026-10-01', 'board_column_id' => $this->done($this->flood)]);

        $this->assertSame(collect([$overdue, $today, $week, $later, $undated])->pluck('id')->sort()->values()->all(), $this->ids($this->myWork()), 'open by default');
        $this->assertSame([$finished->id], $this->ids($this->myWork(['status' => 'done'])));
        $this->assertCount(6, $this->ids($this->myWork(['status' => 'all'])));
        $this->assertSame([$overdue->id], $this->ids($this->myWork(['due' => 'overdue', 'status' => 'all'])), 'a done task is never overdue');
        $this->assertSame([$today->id], $this->ids($this->myWork(['due' => 'today'])));
        $this->assertSame(collect([$today, $week])->pluck('id')->sort()->values()->all(), $this->ids($this->myWork(['due' => 'week'])));
        $this->assertSame([$undated->id], $this->ids($this->myWork(['due' => 'none'])));
        $this->assertSame(collect([$week, $later, $undated])->pluck('id')->sort()->values()->all(), $this->ids($this->myWork(['project' => $this->commercial->id])));
        $this->assertSame([$overdue->id], $this->ids($this->myWork(['search' => 'travelers'])));
        $this->assertSame([], $this->ids($this->myWork(['search' => '%'])), 'wildcards are literal');

        $overdueRow = collect($this->myWork()->inertiaProps('tasks.data'))->firstWhere('id', $overdue->id);
        $this->assertTrue($overdueRow['is_overdue']);
    }

    public function test_due_dates_follow_the_workspace_calendar(): void
    {
        $this->workspace->forceFill(['timezone' => 'Asia/Karachi'])->save();
        $this->travelTo('2026-10-15 20:00:00');
        $task = $this->task($this->flood, ['assigned_to' => $this->employee->id, 'due_date' => '2026-10-16']);

        $this->assertSame([$task->id], $this->ids($this->myWork(['due' => 'today'])), '20:00 UTC is already the 16th in Karachi');
    }

    public function test_project_board_columns_are_never_merged(): void
    {
        $columnA = $this->flood->boardColumns()->where('is_done', false)->orderBy('position')->first();
        $columnA->update(['name' => 'Quoting']);
        $columnB = $this->commercial->boardColumns()->where('is_done', false)->orderBy('position')->first();
        $columnB->update(['name' => 'Underwriting']);
        $a = $this->task($this->flood, ['assigned_to' => $this->employee->id, 'board_column_id' => $columnA->id]);
        $b = $this->task($this->commercial, ['assigned_to' => $this->employee->id, 'board_column_id' => $columnB->id]);

        $rows = collect($this->myWork()->inertiaProps('tasks.data'))->keyBy('id');
        $this->assertSame('Quoting', $rows[$a->id]['column']['name']);
        $this->assertSame('Underwriting', $rows[$b->id]['column']['name']);
    }

    public function test_guests_only_see_projects_their_board_permission_allows(): void
    {
        $guest = User::factory()->create();
        $this->workspace->users()->attach($guest->id, ['role' => UserRole::CLIENT->value]);
        $this->flood->members()->attach($guest->id, ['role' => ProjectRole::MEMBER->value]);
        $task = $this->task($this->flood, ['assigned_to' => $guest->id]);

        $this->assertSame([$task->id], $this->ids($this->myWork([], $guest)), 'default client role can view the board');
        $this->assertSame([], $this->ids($this->myWork(['scope' => 'all', 'project' => $this->secret->id], $guest)));
    }

    public function test_other_workspaces_never_leak(): void
    {
        $outsider = User::factory()->create();
        $theirs = Workspace::factory()->ownedBy($outsider)->create();
        $foreign = Project::factory()->forWorkspace($theirs)->create();
        $foreignTask = $this->task($foreign, ['assigned_to' => $this->employee->id]);
        $theirs->users()->attach($this->employee->id, ['role' => UserRole::MEMBER->value]);

        $this->assertNotContains($foreignTask->id, $this->ids($this->myWork(['scope' => 'all', 'project' => $foreign->id])));
        $this->assertNotContains($foreignTask->id, $this->ids($this->myWork()));

        $this->actingAs($outsider)->get(route('workspace.my-work.index', $this->workspace))->assertNotFound();
    }

    public function test_the_navigation_offers_my_work(): void
    {
        $this->myWork()->assertInertia(fn (Assert $page) => $page->where('navigation.myWork', true));
    }

    public function test_results_are_paginated(): void
    {
        foreach (range(1, 55) as $i) {
            $this->task($this->flood, ['assigned_to' => $this->employee->id]);
        }

        $this->myWork()->assertInertia(fn (Assert $page) => $page->has('tasks.data', 50)->where('tasks.total', 55)->where('tasks.last_page', 2));
        $this->myWork(['page' => 2])->assertInertia(fn (Assert $page) => $page->has('tasks.data', 5));
    }
}
