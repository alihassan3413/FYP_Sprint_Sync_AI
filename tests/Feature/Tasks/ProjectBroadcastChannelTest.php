<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Data\WorkspacePermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

final class ProjectBroadcastChannelTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'reverb']);
        Broadcast::purge('log');
        require base_path('routes/channels.php');

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    public function test_the_owner_can_subscribe_to_a_project_channel(): void
    {
        $this->assertTrue($this->owner->can('view', $this->project));
    }

    public function test_an_admin_can_subscribe_without_project_membership(): void
    {
        $admin = $this->memberOf(UserRole::ADMIN);

        $this->assertTrue($admin->can('view', $this->project));
    }

    public function test_an_assigned_project_member_can_subscribe(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->assertTrue($member->can('view', $this->project));
    }

    public function test_an_unassigned_workspace_member_cannot_subscribe(): void
    {
        $stranger = $this->memberOf(UserRole::MEMBER);

        $this->assertFalse($stranger->can('view', $this->project));
    }

    public function test_a_user_from_another_workspace_cannot_subscribe(): void
    {
        $outsider = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($outsider)->create();
        Project::factory()->forWorkspace($otherWorkspace)->create();

        $this->assertFalse($outsider->can('view', $this->project));
    }

    public function test_a_custom_role_with_project_visibility_can_subscribe(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'permissions' => WorkspacePermission::normalise([WorkspacePermission::ProjectsView->value => true]),
        ]);

        $viewer = User::factory()->create();
        $this->workspace->users()->attach($viewer->id, [
            'role' => UserRole::MEMBER->value,
            'workspace_role_id' => $role->id,
        ]);

        $this->assertTrue($viewer->can('view', $this->project));
    }

    public function test_the_broadcasting_auth_endpoint_rejects_an_unassigned_member(): void
    {
        $stranger = $this->memberOf(UserRole::MEMBER);

        $this->actingAs($stranger)
            ->post('/broadcasting/auth', [
                'channel_name' => "private-project.{$this->project->id}",
                'socket_id' => '1234.5678',
            ])
            ->assertForbidden();
    }

    public function test_the_broadcasting_auth_endpoint_accepts_a_project_member(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($member)
            ->post('/broadcasting/auth', [
                'channel_name' => "private-project.{$this->project->id}",
                'socket_id' => '1234.5678',
            ])
            ->assertOk();
    }

    public function test_the_broadcasting_auth_endpoint_rejects_a_cross_workspace_project(): void
    {
        $outsider = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($outsider)->create();
        $foreignProject = Project::factory()->forWorkspace($otherWorkspace)->create();

        $member = $this->memberOf(UserRole::MEMBER);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($member)
            ->post('/broadcasting/auth', [
                'channel_name' => "private-project.{$foreignProject->id}",
                'socket_id' => '1234.5678',
            ])
            ->assertForbidden();
    }

    public function test_the_broadcasting_auth_endpoint_rejects_an_unknown_project(): void
    {
        $this->actingAs($this->owner)
            ->post('/broadcasting/auth', [
                'channel_name' => 'private-project.999999',
                'socket_id' => '1234.5678',
            ])
            ->assertForbidden();
    }

    public function test_the_broadcasting_auth_endpoint_rejects_a_guest(): void
    {
        $this->post('/broadcasting/auth', [
            'channel_name' => "private-project.{$this->project->id}",
            'socket_id' => '1234.5678',
        ])->assertStatus(403);
    }
}
