<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
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
        $member->forceFill(['current_workspace_id' => $workspace->id])->save();

        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($member->refresh()),
        );

        $this->assertNotContains('invite_user', $names);
        $this->assertContains('get_workspace_info', $names);
    }

    public function test_an_admin_is_offered_the_invite_tool(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();
        $owner->forceFill(['current_workspace_id' => $workspace->id])->save();

        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($owner->refresh()),
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
        $member->forceFill(['current_workspace_id' => $workspace->id])->save();

        $result = app(InvitationTool::class)->execute(['email' => 'x@example.com'], $member->refresh());

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        Mail::assertNothingQueued();
    }

    public function test_the_workspace_info_tool_is_unavailable_without_a_current_workspace(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(app(GetWorkspaceInfoTool::class)->authorize($user));
    }

    public function test_the_create_workspace_tool_respects_the_per_owner_limit(): void
    {
        config(['workspace.max_per_owner' => 1]);

        $user = User::factory()->create();
        Workspace::factory()->ownedBy($user)->create();

        $result = app(CreateWorkspaceTool::class)->execute(['name' => 'Second One'], $user->refresh());

        $this->assertFalse($result['success']);
        $this->assertSame(1, Workspace::query()->where('owner_id', $user->id)->count());
    }
}
