<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TaskCommentTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->member = User::factory()->create();
        $this->workspace->users()->attach($this->member->id, ['role' => UserRole::MEMBER->value]);
        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
        $this->task = Task::factory()->forProject($this->project)->create();
    }

    private function commentRoute(string $name, ?TaskComment $comment = null, array $extra = []): string
    {
        $params = array_merge(['workspace' => $this->workspace, 'project' => $this->project, 'task' => $this->task], $extra);

        if ($comment !== null) {
            $params['comment'] = $comment;
        }

        return route($name, $params);
    }

    public function test_an_owner_can_comment_on_a_task(): void
    {
        $this->actingAs($this->owner)
            ->post($this->commentRoute('workspace.projects.tasks.comments.store'), ['body' => 'Looks good, ship it.'])
            ->assertRedirect();

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $this->task->id,
            'user_id' => $this->owner->id,
            'body' => 'Looks good, ship it.',
        ]);
    }

    public function test_a_project_member_can_comment_on_a_task(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->post($this->commentRoute('workspace.projects.tasks.comments.store'), ['body' => 'What is the deadline for this?'])
            ->assertRedirect();

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $this->task->id,
            'user_id' => $this->member->id,
            'body' => 'What is the deadline for this?',
        ]);
    }

    public function test_a_project_manager_can_comment_on_a_task(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->member)
            ->post($this->commentRoute('workspace.projects.tasks.comments.store'), ['body' => 'Assigning this to you.'])
            ->assertRedirect();

        $this->assertDatabaseHas('task_comments', ['task_id' => $this->task->id, 'user_id' => $this->member->id]);
    }

    public function test_an_unassigned_workspace_member_cannot_comment_on_a_task(): void
    {
        $this->actingAs($this->member)
            ->post($this->commentRoute('workspace.projects.tasks.comments.store'), ['body' => 'Not allowed'])
            ->assertForbidden();

        $this->assertSame(0, $this->task->comments()->count());
    }

    public function test_an_outsider_cannot_comment_on_a_task(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post($this->commentRoute('workspace.projects.tasks.comments.store'), ['body' => 'Nope'])
            ->assertNotFound();
    }

    public function test_the_body_is_required(): void
    {
        $this->actingAs($this->owner)
            ->post($this->commentRoute('workspace.projects.tasks.comments.store'), ['body' => ''])
            ->assertSessionHasErrors('body');
    }

    public function test_the_author_can_delete_their_own_comment(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);
        $comment = TaskComment::factory()->forTask($this->task)->by($this->member)->create();

        $this->actingAs($this->member)
            ->delete($this->commentRoute('workspace.projects.tasks.comments.destroy', $comment))
            ->assertRedirect();

        $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);
    }

    public function test_an_owner_can_delete_any_comment(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);
        $comment = TaskComment::factory()->forTask($this->task)->by($this->member)->create();

        $this->actingAs($this->owner)
            ->delete($this->commentRoute('workspace.projects.tasks.comments.destroy', $comment))
            ->assertRedirect();

        $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);
    }

    public function test_a_project_manager_can_delete_any_comment(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);
        $comment = TaskComment::factory()->forTask($this->task)->by($this->owner)->create();

        $this->actingAs($this->member)
            ->delete($this->commentRoute('workspace.projects.tasks.comments.destroy', $comment))
            ->assertRedirect();

        $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);
    }

    public function test_a_member_cannot_delete_another_members_comment(): void
    {
        $otherMember = User::factory()->create();
        $this->workspace->users()->attach($otherMember->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);
        $this->project->members()->attach($otherMember->id, ['role' => ProjectRole::MEMBER->value]);
        $comment = TaskComment::factory()->forTask($this->task)->by($otherMember)->create();

        $this->actingAs($this->member)
            ->delete($this->commentRoute('workspace.projects.tasks.comments.destroy', $comment))
            ->assertForbidden();

        $this->assertDatabaseHas('task_comments', ['id' => $comment->id]);
    }

    public function test_a_comment_from_another_task_cannot_be_deleted_through_this_task(): void
    {
        $otherTask = Task::factory()->forProject($this->project)->create();
        $foreignComment = TaskComment::factory()->forTask($otherTask)->by($this->owner)->create();

        $this->actingAs($this->owner)
            ->delete($this->commentRoute('workspace.projects.tasks.comments.destroy', $foreignComment))
            ->assertNotFound();

        $this->assertDatabaseHas('task_comments', ['id' => $foreignComment->id]);
    }

    public function test_a_comment_from_another_workspace_cannot_be_reached_through_this_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $otherProject = Project::factory()->forWorkspace($otherWorkspace)->create();
        $otherTask = Task::factory()->forProject($otherProject)->create();
        $foreignComment = TaskComment::factory()->forTask($otherTask)->by($this->owner)->create();

        $this->actingAs($this->owner)
            ->delete($this->commentRoute('workspace.projects.tasks.comments.destroy', $foreignComment))
            ->assertNotFound();

        $this->assertDatabaseHas('task_comments', ['id' => $foreignComment->id]);
    }

    public function test_the_project_show_page_includes_task_comments_for_a_project_member(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);
        TaskComment::factory()->forTask($this->task)->by($this->owner)->create(['body' => 'First comment']);

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/show')
                ->where('tasks.0.comments.0.body', 'First comment')
                ->where('tasks.0.comments.0.user_name', $this->owner->name));
    }

    public function test_an_unassigned_workspace_member_cannot_view_task_comments(): void
    {
        TaskComment::factory()->forTask($this->task)->by($this->owner)->create();

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertForbidden();
    }
}
