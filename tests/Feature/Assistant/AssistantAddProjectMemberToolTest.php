<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Actions\ExecuteToolCall;
use App\Modules\Assistant\Actions\ResolveConversationWorkspace;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Support\ToolArgumentValidator;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\AddProjectMemberTool;
use App\Modules\Assistant\Tools\CreateTaskTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssistantAddProjectMemberToolTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $teammate;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mobile App']);

        $this->teammate = User::factory()->create(['name' => 'Sana Iqbal']);
        $this->workspace->users()->attach($this->teammate->id, ['role' => UserRole::MEMBER->value]);
    }

    private function contextFor(User $user, ?Workspace $workspace = null): ToolContext
    {
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'workspace_id' => ($workspace ?? $this->workspace)->id,
        ]);

        return app(ResolveConversationWorkspace::class)->contextFor($conversation, $user->refresh());
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function add(User $actor, array $args = []): array
    {
        return app(AddProjectMemberTool::class)->execute(array_merge([
            'project_id' => $this->project->id,
            'member_email' => $this->teammate->email,
        ], $args), $this->contextFor($actor));
    }

    public function test_the_tool_is_registered_and_requires_confirmation(): void
    {
        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->contextFor($this->owner)),
        );

        $this->assertContains('add_project_member', $names);
        $this->assertTrue(app(AddProjectMemberTool::class)->requiresConfirmation());
    }

    public function test_it_reproduces_the_original_failure_then_resolves_it(): void
    {
        $failure = app(CreateTaskTool::class)->execute([
            'project_id' => $this->project->id,
            'title' => 'Wire up the settings page',
            'assignee_email' => $this->teammate->email,
        ], $this->contextFor($this->owner));

        $this->assertFalse($failure['success']);
        $this->assertSame('assignee_not_assignable', $failure['error_code']);
        $this->assertStringContainsString('add_project_member', $failure['error']);
        $this->assertSame(0, Task::query()->count());

        $this->assertTrue($this->add($this->owner)['success']);

        $retry = app(CreateTaskTool::class)->execute([
            'project_id' => $this->project->id,
            'title' => 'Wire up the settings page',
            'assignee_email' => $this->teammate->email,
        ], $this->contextFor($this->owner));

        $this->assertTrue($retry['success']);
        $this->assertSame('Sana Iqbal', $retry['task']['assignee_name']);
        $this->assertSame($this->teammate->id, Task::query()->firstOrFail()->assigned_to);
    }

    public function test_a_member_is_added_with_the_member_role_by_default(): void
    {
        $result = $this->add($this->owner);

        $this->assertTrue($result['success']);
        $this->assertSame('member', $result['member']['role']);
        $this->assertTrue($this->project->refresh()->hasMember($this->teammate));
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.member_added']);
    }

    public function test_a_manager_role_can_be_requested(): void
    {
        $result = $this->add($this->owner, ['role' => 'manager']);

        $this->assertSame('manager', $result['member']['role']);
        $this->assertTrue($this->project->refresh()->userHasAtLeast($this->teammate, ProjectRole::MANAGER));
    }

    public function test_an_unknown_role_falls_back_to_member(): void
    {
        $result = app(AddProjectMemberTool::class)->execute([
            'project_id' => $this->project->id,
            'member_email' => $this->teammate->email,
            'role' => 'owner',
        ], $this->contextFor($this->owner));

        $this->assertTrue($result['success']);
        $this->assertSame('member', $result['member']['role']);
    }

    public function test_a_project_manager_can_add_a_member(): void
    {
        $manager = User::factory()->create();
        $this->workspace->users()->attach($manager->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $this->assertTrue($this->add($manager)['success']);
    }

    public function test_a_plain_project_member_cannot_add_anyone(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $result = $this->add($member);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertFalse($this->project->refresh()->hasMember($this->teammate));
    }

    public function test_someone_outside_the_workspace_cannot_be_added(): void
    {
        $stranger = User::factory()->create();

        $result = $this->add($this->owner, ['member_email' => $stranger->email]);

        $this->assertFalse($result['success']);
        $this->assertSame('not_a_workspace_member', $result['error_code']);
        $this->assertFalse($this->project->refresh()->hasMember($stranger));
    }

    public function test_an_unknown_email_is_indistinguishable_from_a_non_member(): void
    {
        $stranger = User::factory()->create();

        $known = $this->add($this->owner, ['member_email' => $stranger->email]);
        $unknown = $this->add($this->owner, ['member_email' => 'nobody@example.com']);

        $this->assertSame($known['error_code'], $unknown['error_code']);
    }

    public function test_adding_someone_twice_is_reported_not_duplicated(): void
    {
        $this->add($this->owner);

        $result = $this->add($this->owner);

        $this->assertFalse($result['success']);
        $this->assertSame('already_a_member', $result['error_code']);
        $this->assertSame(1, $this->project->members()->count());
    }

    public function test_a_project_in_another_workspace_is_unreachable(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $other->users()->attach($this->owner->id, ['role' => UserRole::OWNER->value]);
        $foreign = Project::factory()->forWorkspace($other)->create();

        $result = $this->add($this->owner, ['project_id' => $foreign->id]);

        $this->assertFalse($result['success']);
        $this->assertSame('project_not_found', $result['error_code']);
        $this->assertSame(0, $foreign->members()->count());
    }

    public function test_the_confirmation_card_names_the_person_project_and_role(): void
    {
        $details = app(AddProjectMemberTool::class)->confirmationDetails([
            'project_id' => $this->project->id,
            'member_email' => $this->teammate->email,
            'role' => 'manager',
        ], $this->contextFor($this->owner));

        $this->assertSame('Mobile App', $details['project']);
        $this->assertSame('Sana Iqbal', $details['person']);
        $this->assertSame('Manager', $details['role']);
        $this->assertStringContainsString('see this project', $details['grants']);
    }

    public function test_confirmation_details_do_not_leak_an_inaccessible_project(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $foreign = Project::factory()->forWorkspace($other)->create(['name' => 'Secret Project']);

        $details = app(AddProjectMemberTool::class)->confirmationDetails(
            ['project_id' => $foreign->id, 'member_email' => $this->teammate->email],
            $this->contextFor($this->owner),
        );

        $this->assertSame('Unknown project', $details['project']);
        $this->assertSame('Unknown workspace member', $details['person']);
    }

    public function test_nothing_happens_until_the_pending_call_is_confirmed(): void
    {
        $conversation = Conversation::create([
            'user_id' => $this->owner->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $pending = Message::factory()
            ->pendingTool('add_project_member', [
                'project_id' => $this->project->id,
                'member_email' => $this->teammate->email,
            ])
            ->create(['conversation_id' => $conversation->id]);

        $this->assertFalse($this->project->refresh()->hasMember($this->teammate));

        $tool = app(AddProjectMemberTool::class);
        $args = app(ToolArgumentValidator::class)->validate($tool, $pending->metadata['args'] ?? []);

        $result = app(ExecuteToolCall::class)->handle(
            $tool,
            $args,
            app(ResolveConversationWorkspace::class)->contextFor($pending->conversation, $this->owner),
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($this->project->refresh()->hasMember($this->teammate));
    }

    public function test_execute_tool_call_refuses_the_tool_without_workspace_context(): void
    {
        $conversation = Conversation::create(['user_id' => $this->owner->id, 'workspace_id' => null]);
        $context = app(ResolveConversationWorkspace::class)->contextFor($conversation, $this->owner);

        $result = app(ExecuteToolCall::class)->handle(app(AddProjectMemberTool::class), [], $context);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
    }
}
