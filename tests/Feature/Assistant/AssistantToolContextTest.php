<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Actions\ExecuteToolCall;
use App\Modules\Assistant\Actions\ResolveConversationWorkspace;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Support\ToolArgumentValidator;
use App\Modules\Assistant\Tools\GetWorkspaceInfoTool;
use App\Modules\Assistant\Tools\InvitationTool;
use App\Modules\Assistant\Tools\ListProjectsTool;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AssistantToolContextTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspaceA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspaceA = Workspace::factory()->ownedBy($this->owner)->create(['name' => 'Alpha']);
        $this->owner->forceFill(['current_workspace_id' => $this->workspaceA->id])->save();
    }

    private function conversationFor(User $user, ?Workspace $workspace): Conversation
    {
        return Conversation::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace?->id,
        ]);
    }

    private function memberOf(Workspace $workspace, UserRole $role): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role->value]);
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return $user;
    }

    public function test_the_conversation_workspace_wins_over_the_users_current_workspace(): void
    {
        Mail::fake();

        $workspaceB = Workspace::factory()->ownedBy($this->owner)->create(['name' => 'Beta']);

        $this->owner->forceFill(['current_workspace_id' => $workspaceB->id])->save();

        $conversation = $this->conversationFor($this->owner, $this->workspaceA);
        $context = app(ResolveConversationWorkspace::class)->contextFor($conversation, $this->owner->refresh());

        $this->assertSame($this->workspaceA->id, $context->workspace?->id);

        $result = app(InvitationTool::class)->execute(['email' => 'new@example.com'], $context);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('workspace_invitations', [
            'email' => 'new@example.com',
            'workspace_id' => $this->workspaceA->id,
        ]);
        $this->assertDatabaseMissing('workspace_invitations', ['workspace_id' => $workspaceB->id]);
    }

    public function test_a_removed_member_loses_the_stale_conversation_workspace(): void
    {
        $member = $this->memberOf($this->workspaceA, UserRole::MEMBER);
        $conversation = $this->conversationFor($member, $this->workspaceA);

        $this->workspaceA->users()->detach($member->id);

        $context = app(ResolveConversationWorkspace::class)->contextFor($conversation, $member->refresh());

        $this->assertNull($context->workspace);
        $this->assertNull($conversation->refresh()->workspace_id);
        $this->assertFalse(app(GetWorkspaceInfoTool::class)->authorize($context));
    }

    public function test_a_deleted_workspace_clears_the_conversation_context(): void
    {
        $extra = Workspace::factory()->ownedBy($this->owner)->create();
        $conversation = $this->conversationFor($this->owner, $extra);

        $extra->delete();

        $context = app(ResolveConversationWorkspace::class)->contextFor($conversation, $this->owner->refresh());

        $this->assertNull($context->workspace);
        $this->assertNull($conversation->refresh()->workspace_id);
    }

    public function test_creating_a_workspace_moves_the_conversation_context_forward(): void
    {
        $conversation = $this->conversationFor($this->owner, $this->workspaceA);

        $newWorkspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $newWorkspace->id])->save();

        app(ResolveConversationWorkspace::class)->syncFromUser($conversation, $this->owner);

        $this->assertSame($newWorkspace->id, $conversation->refresh()->workspace_id);
    }

    public function test_auto_run_tool_arguments_are_validated_before_execution(): void
    {
        $tool = app(ListProjectsTool::class);

        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate($tool, ['search' => str_repeat('x', 200)]);
    }

    public function test_an_invalid_enum_argument_is_rejected_for_a_read_only_tool(): void
    {
        $tool = app(GetWorkspaceInfoTool::class);

        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate($tool, ['role_filter' => 'superadmin']);
    }

    public function test_valid_read_only_arguments_pass_validation(): void
    {
        $validated = app(ToolArgumentValidator::class)->validate(
            app(GetWorkspaceInfoTool::class),
            ['include_members' => true, 'role_filter' => 'admin', 'unknown_key' => 'dropped'],
        );

        $this->assertTrue($validated['include_members']);
        $this->assertSame('admin', $validated['role_filter']);
        $this->assertArrayNotHasKey('unknown_key', $validated);
    }

    public function test_execute_tool_call_refuses_a_tool_without_workspace_context(): void
    {
        $conversation = $this->conversationFor($this->owner, null);
        $context = app(ResolveConversationWorkspace::class)->contextFor($conversation, $this->owner);

        $result = app(ExecuteToolCall::class)->handle(app(ListProjectsTool::class), [], $context);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
    }

    public function test_list_projects_returns_only_projects_a_member_is_assigned_to(): void
    {
        $member = $this->memberOf($this->workspaceA, UserRole::MEMBER);

        $assigned = Project::factory()->forWorkspace($this->workspaceA)->create(['name' => 'Assigned']);
        $assigned->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        Project::factory()->forWorkspace($this->workspaceA)->create(['name' => 'Hidden']);

        $result = $this->listProjectsFor($member);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(['Assigned'], array_column($result['projects'], 'name'));
        $this->assertSame(ProjectRole::MEMBER->value, $result['projects'][0]['project_role']);
    }

    public function test_list_projects_returns_a_managed_project_for_a_project_manager(): void
    {
        $manager = $this->memberOf($this->workspaceA, UserRole::MEMBER);

        $managed = Project::factory()->forWorkspace($this->workspaceA)->create(['name' => 'Managed']);
        $managed->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $result = $this->listProjectsFor($manager);

        $this->assertSame(['Managed'], array_column($result['projects'], 'name'));
        $this->assertSame(ProjectRole::MANAGER->value, $result['projects'][0]['project_role']);
    }

    public function test_list_projects_is_empty_for_an_unassigned_workspace_member(): void
    {
        $outsider = $this->memberOf($this->workspaceA, UserRole::MEMBER);

        Project::factory()->forWorkspace($this->workspaceA)->create(['name' => 'Secret']);

        $result = $this->listProjectsFor($outsider);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['projects']);
    }

    public function test_list_projects_returns_every_project_for_an_admin(): void
    {
        $admin = $this->memberOf($this->workspaceA, UserRole::ADMIN);

        Project::factory()->forWorkspace($this->workspaceA)->create(['name' => 'One']);
        Project::factory()->forWorkspace($this->workspaceA)->create(['name' => 'Two']);

        $result = $this->listProjectsFor($admin);

        $this->assertSame(2, $result['total']);
        $this->assertSame(['One', 'Two'], array_column($result['projects'], 'name'));
        $this->assertNull($result['projects'][0]['project_role']);
    }

    public function test_list_projects_never_returns_projects_from_another_workspace(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $other->users()->attach($this->owner->id, ['role' => UserRole::OWNER->value]);

        Project::factory()->forWorkspace($other)->create(['name' => 'Foreign']);
        Project::factory()->forWorkspace($this->workspaceA)->create(['name' => 'Local']);

        $result = $this->listProjectsFor($this->owner);

        $this->assertSame(['Local'], array_column($result['projects'], 'name'));
    }

    public function test_list_projects_supports_a_search_filter(): void
    {
        Project::factory()->forWorkspace($this->workspaceA)->create(['name' => 'Apollo']);
        Project::factory()->forWorkspace($this->workspaceA)->create(['name' => 'Zephyr']);

        $result = $this->listProjectsFor($this->owner, ['search' => 'apol']);

        $this->assertSame(['Apollo'], array_column($result['projects'], 'name'));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function listProjectsFor(User $user, array $args = []): array
    {
        $conversation = $this->conversationFor($user, $this->workspaceA);
        $context = app(ResolveConversationWorkspace::class)->contextFor($conversation, $user->refresh());

        return app(ListProjectsTool::class)->execute($args, $context);
    }
}
