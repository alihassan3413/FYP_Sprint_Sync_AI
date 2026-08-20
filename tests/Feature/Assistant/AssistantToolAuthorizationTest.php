<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\CreateWorkspaceTool;
use App\Modules\Assistant\Tools\GetWorkspaceInfoTool;
use App\Modules\Assistant\Tools\InvitationTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AssistantToolAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_is_not_offered_the_invite_tool(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $member = User::factory()->create();
        $workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor(new ToolContext($member->refresh(), $workspace)),
        );

        $this->assertNotContains('invite_user', $names);
        $this->assertContains('get_workspace_info', $names);
        $this->assertContains('list_projects', $names);
    }

    public function test_an_admin_is_offered_the_invite_tool(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor(new ToolContext($owner->refresh(), $workspace)),
        );

        $this->assertContains('invite_user', $names);
    }

    public function test_the_invite_tool_refuses_to_run_for_a_plain_member(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $member = User::factory()->create();
        $workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        $result = app(InvitationTool::class)->execute(
            ['email' => 'x@example.com'],
            new ToolContext($member->refresh(), $workspace),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        Mail::assertNothingQueued();
    }

    public function test_the_workspace_info_tool_is_unavailable_without_a_workspace_context(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(app(GetWorkspaceInfoTool::class)->authorize(new ToolContext($user, null)));
    }

    public function test_only_the_guide_and_create_workspace_tools_are_available_without_a_workspace_context(): void
    {
        $user = User::factory()->create();

        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor(new ToolContext($user, null)),
        );

        /* Only the two tools that make sense before a workspace exists: how to
           use the product, and how to create one. */
        $this->assertEqualsCanonicalizing(['get_guide', 'create_workspace'], $names);
    }

    public function test_the_create_workspace_tool_works_without_an_existing_workspace(): void
    {
        $user = User::factory()->create();

        $result = app(CreateWorkspaceTool::class)->execute(
            ['name' => 'First Space'],
            new ToolContext($user, null),
        );

        $this->assertTrue($result['success']);
        $this->assertSame('First Space', $result['workspace']['name']);
        $this->assertDatabaseHas('workspaces', ['owner_id' => $user->id, 'name' => 'First Space']);
        $this->assertSame($result['workspace']['id'], $user->refresh()->current_workspace_id);
    }

    public function test_the_create_workspace_tool_respects_the_per_owner_limit(): void
    {
        config(['workspace.max_per_owner' => 1]);

        $user = User::factory()->create();
        Workspace::factory()->ownedBy($user)->create();

        $result = app(CreateWorkspaceTool::class)->execute(
            ['name' => 'Second One'],
            new ToolContext($user->refresh(), null),
        );

        $this->assertFalse($result['success']);
        $this->assertSame(1, Workspace::query()->where('owner_id', $user->id)->count());
    }
}
