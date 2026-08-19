<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\GetWorkspaceInfoTool;
use App\Modules\Assistant\Tools\InvitationTool;
use App\Modules\Workspace\Data\ClientPermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AssistantCustomRoleToolTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create(['name' => 'Alpha']);
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    }

    private function context(): ToolContext
    {
        return new ToolContext($this->owner->refresh(), $this->workspace->fresh());
    }

    public function test_the_assistant_can_invite_someone_with_a_custom_role(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'QA Lead',
            'slug' => 'qa-lead',
        ]);

        $result = app(InvitationTool::class)->execute([
            'email' => 'qa@example.com',
            'custom_role' => 'QA Lead',
        ], $this->context());

        $this->assertTrue($result['success']);
        $this->assertSame('QA Lead', $result['invitation']['custom_role']);
        $this->assertStringContainsString('QA Lead', $result['message']);

        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_id' => $this->workspace->id,
            'email' => 'qa@example.com',
            'role' => UserRole::MEMBER->value,
            'workspace_role_id' => $role->id,
        ]);
    }

    public function test_a_custom_role_name_is_matched_case_insensitively(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'QA Lead',
            'slug' => 'qa-lead',
        ]);

        $result = app(InvitationTool::class)->execute([
            'email' => 'qa@example.com',
            'role' => UserRole::ADMIN->value,
            'custom_role' => 'qa lead',
        ], $this->context());

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('workspace_invitations', [
            'email' => 'qa@example.com',
            'role' => UserRole::ADMIN->value,
            'workspace_role_id' => $role->id,
        ]);
    }

    public function test_an_unknown_custom_role_is_rejected_and_the_real_roles_are_returned(): void
    {
        WorkspaceRole::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'QA Lead']);

        $result = app(InvitationTool::class)->execute([
            'email' => 'qa@example.com',
            'custom_role' => 'Chief Wizard',
        ], $this->context());

        $this->assertFalse($result['success']);
        $this->assertSame('unknown_custom_role', $result['error_code']);
        $this->assertSame(['QA Lead'], $result['available_custom_roles']);
        $this->assertDatabaseCount('workspace_invitations', 0);
        Mail::assertNothingQueued();
    }

    public function test_a_custom_role_from_another_workspace_cannot_be_used(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        WorkspaceRole::factory()->create(['workspace_id' => $otherWorkspace->id, 'name' => 'Foreign Role']);

        $result = app(InvitationTool::class)->execute([
            'email' => 'qa@example.com',
            'custom_role' => 'Foreign Role',
        ], $this->context());

        $this->assertFalse($result['success']);
        $this->assertSame('unknown_custom_role', $result['error_code']);
        $this->assertDatabaseCount('workspace_invitations', 0);
    }

    public function test_the_workspace_info_tool_reports_custom_roles_and_their_permissions(): void
    {
        WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'QA Lead',
            'slug' => 'qa-lead',
            'permissions' => ['projects.view' => true, 'projects.create' => true, 'billing.manage' => false],
        ]);

        $result = app(GetWorkspaceInfoTool::class)->execute(['include_roles' => true], $this->context());

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['stats']['custom_roles_count']);
        $this->assertCount(1, $result['custom_roles']);

        $role = $result['custom_roles'][0];

        $this->assertSame('QA Lead', $role['name']);
        $this->assertSame(['projects.view', 'projects.create'], $role['permissions']);
        $this->assertSame(0, $role['members_count']);

        $this->assertNotEmpty($result['base_roles']);
    }

    public function test_the_workspace_info_tool_reports_the_custom_role_of_members_and_invitations(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'QA Lead',
        ]);

        $member = User::factory()->create(['name' => 'Zoe Tester']);
        $this->workspace->users()->attach($member->id, [
            'role' => UserRole::MEMBER->value,
            'workspace_role_id' => $role->id,
        ]);

        app(InvitationTool::class)->execute([
            'email' => 'pending@example.com',
            'custom_role' => 'QA Lead',
        ], $this->context());

        $result = app(GetWorkspaceInfoTool::class)->execute([
            'include_members' => true,
            'include_invitations' => true,
            'custom_role_filter' => 'QA Lead',
        ], $this->context());

        $this->assertCount(1, $result['members']);
        $this->assertSame('Zoe Tester', $result['members'][0]['name']);
        $this->assertSame('QA Lead', $result['members'][0]['custom_role']);

        $this->assertCount(1, $result['pending_invitations']);
        $this->assertSame('QA Lead', $result['pending_invitations'][0]['custom_role']);
    }

    public function test_the_assistant_can_invite_a_client_with_a_client_role(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Client — feedback',
            'permissions' => [ClientPermission::TasksComment->value => true],
        ]);

        $result = app(InvitationTool::class)->execute([
            'email' => 'client@example.com',
            'role' => UserRole::CLIENT->value,
            'custom_role' => 'Client — feedback',
        ], $this->context());

        $this->assertTrue($result['success']);
        $this->assertSame(UserRole::CLIENT->value, $result['invitation']['role']);

        $this->assertDatabaseHas('workspace_invitations', [
            'email' => 'client@example.com',
            'role' => UserRole::CLIENT->value,
            'workspace_role_id' => $role->id,
        ]);
    }

    public function test_the_workspace_info_tool_reports_the_client_permissions_of_a_role(): void
    {
        WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Client — feedback',
            'permissions' => [
                ClientPermission::BoardView->value => true,
                ClientPermission::TasksComment->value => true,
                ClientPermission::TasksClose->value => false,
            ],
        ]);

        $result = app(GetWorkspaceInfoTool::class)->execute(['include_roles' => true], $this->context());

        $this->assertSame(
            [ClientPermission::BoardView->value, ClientPermission::TasksComment->value],
            $result['custom_roles'][0]['client_permissions'],
        );
        $this->assertContains(UserRole::CLIENT->value, array_column($result['base_roles'], 'value'));
    }

    public function test_a_client_asking_the_assistant_never_gets_the_member_roster(): void
    {
        $client = User::factory()->create();
        $this->workspace->users()->attach($client->id, ['role' => UserRole::CLIENT->value]);

        $result = app(GetWorkspaceInfoTool::class)->execute([
            'include_members' => true,
            'include_invitations' => true,
            'include_roles' => true,
        ], new ToolContext($client->refresh(), $this->workspace->fresh()));

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('members', $result);
        $this->assertArrayNotHasKey('pending_invitations', $result);
        $this->assertArrayNotHasKey('custom_roles', $result);
        $this->assertArrayNotHasKey('stats', $result);
        $this->assertSame(UserRole::CLIENT->value, $result['current_user']['role']);
    }

    public function test_filtering_members_by_an_unknown_custom_role_returns_nobody(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        $result = app(GetWorkspaceInfoTool::class)->execute([
            'include_members' => true,
            'custom_role_filter' => 'Chief Wizard',
        ], $this->context());

        $this->assertSame([], $result['members']);
    }
}
