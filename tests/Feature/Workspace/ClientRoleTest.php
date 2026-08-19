<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Actions\ResolveWorkspaceCapabilities;
use App\Modules\Workspace\Data\ClientPermission;
use App\Modules\Workspace\Data\WorkspacePermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ClientRoleTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $client;

    private Project $project;

    private Project $otherProject;

    private BoardColumn $todoColumn;

    private BoardColumn $doneColumn;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Acme Redesign']);
        $this->otherProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Internal Tooling']);

        $this->todoColumn = $this->project->boardColumns()->where('position', 0)->firstOrFail();
        $this->doneColumn = $this->project->boardColumns()->where('is_done', true)->firstOrFail();

        $this->client = User::factory()->create(['name' => 'Casey Client']);
        $this->workspace->users()->attach($this->client->id, ['role' => UserRole::CLIENT->value]);
        $this->client->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->project->members()->attach($this->client->id, ['role' => ProjectRole::MEMBER->value]);
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    private function giveClientRole(array $permissions): WorkspaceRole
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Client role',
            'permissions' => $permissions,
        ]);

        $this->workspace->users()->updateExistingPivot($this->client->id, ['workspace_role_id' => $role->id]);
        $this->workspace->forgetResolvedMembership();

        return $role;
    }

    private function taskOnProject(): Task
    {
        return Task::factory()->create([
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $this->todoColumn->id,
        ]);
    }

    public function test_a_client_can_be_invited_with_a_client_role_and_joins_as_a_client(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Client — feedback',
            'permissions' => [ClientPermission::TasksComment->value => true],
        ]);

        $this->actingAs($this->owner)
            ->post(route('workspace.invitations.store', $this->workspace), [
                'email' => 'new-client@example.com',
                'role' => UserRole::CLIENT->value,
                'workspace_role_id' => $role->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $invitation = WorkspaceInvitation::query()->where('email', 'new-client@example.com')->firstOrFail();

        $this->assertSame(UserRole::CLIENT, $invitation->role);
        $this->assertSame($role->id, $invitation->workspace_role_id);
    }

    public function test_accepting_a_client_invitation_joins_as_a_client_with_their_client_role(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Client — feedback',
            'permissions' => [ClientPermission::TasksComment->value => true],
        ]);

        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'invited_by' => $this->owner->id,
            'email' => 'new-client@example.com',
            'role' => UserRole::CLIENT,
            'workspace_role_id' => $role->id,
        ]);

        $this->post(route('workspace.invitations.accept.store', $invitation->token), [
            'name' => 'New Client',
            'password' => 'Password!2345',
            'password_confirmation' => 'Password!2345',
        ])->assertRedirect(route('dashboard', $this->workspace));

        $joined = User::query()->where('email', 'new-client@example.com')->firstOrFail();
        $workspace = $this->workspace->fresh();

        $this->assertSame(UserRole::CLIENT, $workspace->roleFor($joined));
        $this->assertTrue($workspace->allowsClient($joined, ClientPermission::TasksComment));
        $this->assertFalse($workspace->allowsClient($joined, ClientPermission::TasksClose));
    }

    public function test_a_client_only_sees_the_projects_they_are_added_to(): void
    {
        $this->actingAs($this->client)
            ->get(route('workspace.projects.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('projects', 1)->where('projects.0.name', 'Acme Redesign'));

        $this->actingAs($this->client)
            ->get(route('workspace.projects.show', [$this->workspace, $this->otherProject]))
            ->assertForbidden();
    }

    public function test_a_client_cannot_open_the_team_roster(): void
    {
        $this->actingAs($this->client)
            ->get(route('workspace.teams.index', $this->workspace))
            ->assertForbidden();
    }

    public function test_client_capabilities_hide_every_workspace_area(): void
    {
        $capabilities = app(ResolveWorkspaceCapabilities::class)->handle($this->workspace, $this->client);

        $this->assertTrue($capabilities->viewProjects);
        $this->assertFalse($capabilities->viewTeam);
        $this->assertFalse($capabilities->viewAnalytics);
        $this->assertFalse($capabilities->viewArchive);
        $this->assertFalse($capabilities->viewAudit);
        $this->assertFalse($capabilities->viewWorkspaceSettings);
        $this->assertFalse($capabilities->createProjects);
        $this->assertFalse($capabilities->inviteMembers);
    }

    public function test_a_client_without_a_custom_role_is_read_only(): void
    {
        $task = $this->taskOnProject();

        $this->actingAs($this->client)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('tasks', 1)->where('canManageTasks', false));

        $this->actingAs($this->client)
            ->post(route('workspace.projects.tasks.comments.store', [$this->workspace, $this->project, $task]), ['body' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($this->client)
            ->post(route('workspace.projects.tasks.store', [$this->workspace, $this->project]), ['title' => 'Please build this'])
            ->assertForbidden();

        $this->actingAs($this->client)
            ->patch(route('workspace.projects.tasks.update-status', [$this->workspace, $this->project, $task]), [
                'board_column_id' => $this->doneColumn->id,
            ])
            ->assertForbidden();
    }

    public function test_a_client_role_can_grant_commenting(): void
    {
        $this->giveClientRole([ClientPermission::BoardView->value => true, ClientPermission::TasksComment->value => true]);

        $task = $this->taskOnProject();

        $this->actingAs($this->client)
            ->post(route('workspace.projects.tasks.comments.store', [$this->workspace, $this->project, $task]), [
                'body' => 'Looks good to me.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $task->id,
            'user_id' => $this->client->id,
            'body' => 'Looks good to me.',
        ]);
    }

    public function test_a_client_role_can_grant_requesting_tasks_but_never_assigning_them(): void
    {
        $this->giveClientRole([ClientPermission::BoardView->value => true, ClientPermission::TasksRequest->value => true]);

        $this->actingAs($this->client)
            ->post(route('workspace.projects.tasks.store', [$this->workspace, $this->project]), [
                'title' => 'Please add a dark mode',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $task = Task::query()->where('title', 'Please add a dark mode')->firstOrFail();

        $this->assertSame($this->todoColumn->id, $task->board_column_id);
        $this->assertNull($task->assigned_to);

        $this->actingAs($this->client)
            ->post(route('workspace.projects.tasks.store', [$this->workspace, $this->project]), [
                'title' => 'Assign this to the owner',
                'assigned_to' => $this->owner->id,
            ])
            ->assertSessionHasErrors('assigned_to');
    }

    public function test_a_client_can_close_a_task_but_not_move_it_back(): void
    {
        $this->giveClientRole([ClientPermission::BoardView->value => true, ClientPermission::TasksClose->value => true]);

        $task = $this->taskOnProject();

        $this->actingAs($this->client)
            ->patch(route('workspace.projects.tasks.update-status', [$this->workspace, $this->project, $task]), [
                'board_column_id' => $this->doneColumn->id,
            ])
            ->assertRedirect();

        $this->assertSame($this->doneColumn->id, $task->fresh()->board_column_id);

        $this->actingAs($this->client)
            ->patch(route('workspace.projects.tasks.update-status', [$this->workspace, $this->project, $task]), [
                'board_column_id' => $this->todoColumn->id,
            ])
            ->assertForbidden();

        $this->assertSame($this->doneColumn->id, $task->fresh()->board_column_id);
    }

    public function test_a_client_without_the_board_permission_sees_no_tasks_or_meetings(): void
    {
        $this->giveClientRole([]);

        $this->taskOnProject();

        $this->actingAs($this->client)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tasks', 0)
                ->has('boardColumns', 0)
                ->has('sprints', 0)
                ->has('meetings', 0)
                ->has('projectMembers', 0)
                ->where('canViewBoard', false)
                ->where('activeSprintReport', null));
    }

    public function test_a_client_cannot_open_analytics_or_the_archive(): void
    {
        $this->actingAs($this->client)
            ->get(route('workspace.analytics.index', $this->workspace))
            ->assertForbidden();

        $this->actingAs($this->client)
            ->get(route('workspace.archive.index', $this->workspace))
            ->assertForbidden();
    }

    public function test_the_client_dashboard_hides_task_stats_and_meetings_they_cannot_see(): void
    {
        $this->giveClientRole([]);

        $this->taskOnProject();

        $this->actingAs($this->client)
            ->get(route('dashboard', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('taskProgress.total', 0)
                ->has('upcomingMeetings', 0)
                ->has('members', 0)
                ->has('activity', 0)
                ->where('pendingInvitesCount', 0));
    }

    public function test_a_client_cannot_be_assigned_a_task_by_the_team(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.projects.tasks.store', [$this->workspace, $this->project]), [
                'title' => 'Internal work',
                'assigned_to' => $this->client->id,
            ])
            ->assertSessionHasErrors('assigned_to');
    }

    public function test_a_client_cannot_be_made_a_project_manager(): void
    {
        $this->actingAs($this->owner)
            ->patch(route('workspace.projects.members.update', [$this->workspace, $this->project, $this->client]), [
                'role' => ProjectRole::MANAGER->value,
            ])
            ->assertSessionHasErrors('role');

        $this->assertFalse($this->project->fresh()->userHasAtLeast($this->client, ProjectRole::MANAGER));
    }

    public function test_a_client_never_gains_workspace_permissions_from_a_custom_role(): void
    {
        $this->giveClientRole([
            WorkspacePermission::ProjectsCreate->value => true,
            WorkspacePermission::ProjectsView->value => true,
            WorkspacePermission::MembersInvite->value => true,
        ]);

        $workspace = $this->workspace->fresh();

        $this->assertFalse($workspace->allows($this->client, WorkspacePermission::ProjectsCreate));
        $this->assertFalse($workspace->allows($this->client, WorkspacePermission::MembersInvite));
        $this->assertSame([], array_intersect(WorkspacePermission::values(), $workspace->grantedPermissionsFor($this->client)));

        $this->actingAs($this->client)
            ->post(route('workspace.projects.store', $this->workspace), ['name' => 'Client project'])
            ->assertForbidden();

        $this->actingAs($this->client)
            ->get(route('workspace.projects.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('projects', 1));
    }
}
