<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\TaskStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TaskTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->member = User::factory()->create();
        $this->workspace->users()->attach($this->member->id, ['role' => UserRole::MEMBER->value]);
        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
    }

    private function taskRoute(string $name, ?Task $task = null, array $extra = []): string
    {
        $params = array_merge(['workspace' => $this->workspace, 'project' => $this->project], $extra);

        if ($task !== null) {
            $params['task'] = $task;
        }

        return route($name, $params);
    }

    public function test_an_owner_can_create_a_task(): void
    {
        $this->actingAs($this->owner)
            ->post($this->taskRoute('workspace.projects.tasks.store'), [
                'title' => 'Wireframe the onboarding flow',
                'description' => 'Cover empty states too.',
                'due_date' => '2026-09-01',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('tasks', [
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'title' => 'Wireframe the onboarding flow',
            'status' => TaskStatus::TODO->value,
        ]);
    }

    public function test_a_new_task_defaults_to_to_do(): void
    {
        $this->actingAs($this->owner)
            ->post($this->taskRoute('workspace.projects.tasks.store'), ['title' => 'Fresh task'])
            ->assertRedirect();

        $task = Task::query()->where('title', 'Fresh task')->firstOrFail();

        $this->assertSame(TaskStatus::TODO, $task->status);
    }

    public function test_a_task_can_be_created_with_an_assignee(): void
    {
        $this->actingAs($this->owner)
            ->post($this->taskRoute('workspace.projects.tasks.store'), [
                'title' => 'Assigned task',
                'assigned_to' => $this->member->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'title' => 'Assigned task',
            'assigned_to' => $this->member->id,
        ]);
    }

    public function test_a_task_cannot_be_assigned_to_someone_outside_the_workspace(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->owner)
            ->post($this->taskRoute('workspace.projects.tasks.store'), [
                'title' => 'Bad assignment',
                'assigned_to' => $outsider->id,
            ])
            ->assertSessionHasErrors('assigned_to');

        $this->assertDatabaseMissing('tasks', ['title' => 'Bad assignment']);
    }

    public function test_the_title_is_required(): void
    {
        $this->actingAs($this->owner)
            ->post($this->taskRoute('workspace.projects.tasks.store'), ['description' => 'No title'])
            ->assertSessionHasErrors('title');

        $this->assertSame(0, $this->project->tasks()->count());
    }

    public function test_a_member_cannot_create_a_task(): void
    {
        $this->actingAs($this->member)
            ->post($this->taskRoute('workspace.projects.tasks.store'), ['title' => 'Not allowed'])
            ->assertForbidden();

        $this->assertSame(0, $this->project->tasks()->count());
    }

    public function test_an_owner_can_update_a_task(): void
    {
        $task = Task::factory()->forProject($this->project)->create(['title' => 'Old title']);

        $this->actingAs($this->owner)
            ->put($this->taskRoute('workspace.projects.tasks.update', $task), [
                'title' => 'New title',
                'description' => 'Updated.',
                'assigned_to' => $this->member->id,
                'due_date' => '2026-10-01',
            ])
            ->assertRedirect();

        $task->refresh();

        $this->assertSame('New title', $task->title);
        $this->assertSame('Updated.', $task->description);
        $this->assertSame($this->member->id, $task->assigned_to);
        $this->assertSame('2026-10-01', $task->due_date->toDateString());
    }

    public function test_a_member_cannot_fully_update_a_task_even_if_assigned_to_them(): void
    {
        $task = Task::factory()->forProject($this->project)->assignedTo($this->member)->create(['title' => 'Untouchable']);

        $this->actingAs($this->member)
            ->put($this->taskRoute('workspace.projects.tasks.update', $task), ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Untouchable', $task->fresh()->title);
    }

    public function test_the_assignee_can_change_their_own_tasks_status(): void
    {
        $task = Task::factory()->forProject($this->project)->assignedTo($this->member)->create();

        $this->actingAs($this->member)
            ->patch($this->taskRoute('workspace.projects.tasks.update-status', $task), [
                'status' => TaskStatus::IN_PROGRESS->value,
            ])
            ->assertRedirect();

        $this->assertSame(TaskStatus::IN_PROGRESS, $task->fresh()->status);
    }

    public function test_a_member_cannot_change_the_status_of_a_task_assigned_to_someone_else(): void
    {
        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id, ['role' => UserRole::MEMBER->value]);
        $task = Task::factory()->forProject($this->project)->assignedTo($other)->create();

        $this->actingAs($this->member)
            ->patch($this->taskRoute('workspace.projects.tasks.update-status', $task), [
                'status' => TaskStatus::DONE->value,
            ])
            ->assertForbidden();

        $this->assertSame(TaskStatus::TODO, $task->fresh()->status);
    }

    public function test_a_member_cannot_change_the_status_of_an_unassigned_task(): void
    {
        $task = Task::factory()->forProject($this->project)->create();

        $this->actingAs($this->member)
            ->patch($this->taskRoute('workspace.projects.tasks.update-status', $task), [
                'status' => TaskStatus::DONE->value,
            ])
            ->assertForbidden();
    }

    public function test_an_owner_can_change_the_status_of_any_task(): void
    {
        $task = Task::factory()->forProject($this->project)->assignedTo($this->member)->create();

        $this->actingAs($this->owner)
            ->patch($this->taskRoute('workspace.projects.tasks.update-status', $task), [
                'status' => TaskStatus::DONE->value,
            ])
            ->assertRedirect();

        $this->assertSame(TaskStatus::DONE, $task->fresh()->status);
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $task = Task::factory()->forProject($this->project)->assignedTo($this->member)->create();

        $this->actingAs($this->member)
            ->patch($this->taskRoute('workspace.projects.tasks.update-status', $task), [
                'status' => 'archived',
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_an_owner_can_delete_a_task(): void
    {
        $task = Task::factory()->forProject($this->project)->create();

        $this->actingAs($this->owner)
            ->delete($this->taskRoute('workspace.projects.tasks.destroy', $task))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_a_member_cannot_delete_a_task(): void
    {
        $task = Task::factory()->forProject($this->project)->create();

        $this->actingAs($this->member)
            ->delete($this->taskRoute('workspace.projects.tasks.destroy', $task))
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_an_outsider_cannot_create_a_task(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post($this->taskRoute('workspace.projects.tasks.store'), ['title' => 'Nope'])
            ->assertNotFound();
    }

    public function test_a_task_from_another_project_cannot_be_updated_through_this_project(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();
        $foreignTask = Task::factory()->forProject($otherProject)->create(['title' => 'Foreign']);

        $this->actingAs($this->owner)
            ->put($this->taskRoute('workspace.projects.tasks.update', $foreignTask), ['title' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Foreign', $foreignTask->fresh()->title);
    }

    public function test_a_task_from_another_workspace_cannot_be_reached_through_this_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $otherProject = Project::factory()->forWorkspace($otherWorkspace)->create();
        $foreignTask = Task::factory()->forProject($otherProject)->create();

        $this->actingAs($this->owner)
            ->delete($this->taskRoute('workspace.projects.tasks.destroy', $foreignTask))
            ->assertNotFound();

        $this->assertDatabaseHas('tasks', ['id' => $foreignTask->id]);
    }

    public function test_the_project_show_page_includes_tasks_and_members_for_the_board(): void
    {
        Task::factory()->forProject($this->project)->assignedTo($this->member)->create();
        Task::factory()->forProject($this->project)->withStatus(TaskStatus::DONE)->create();

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/show')
                ->has('tasks', 2)
                ->has('members', 2)
                ->where('canManageTasks', true));
    }

    public function test_deleting_a_project_deletes_its_tasks(): void
    {
        $task = Task::factory()->forProject($this->project)->create();

        $this->project->delete();

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }
}
