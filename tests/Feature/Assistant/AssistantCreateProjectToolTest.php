<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Actions\ResolveConversationWorkspace;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Support\ToolArgumentValidator;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\CreateProjectTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AssistantCreateProjectToolTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create(['name' => 'Alpha']);
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user;
    }

    private function contextFor(User $user): ToolContext
    {
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        return app(ResolveConversationWorkspace::class)->contextFor($conversation, $user->refresh());
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function create(User $user, array $args): array
    {
        return app(CreateProjectTool::class)->execute($args, $this->contextFor($user));
    }

    public function test_an_owner_can_create_a_project(): void
    {
        $result = $this->create($this->owner, ['name' => 'Apollo', 'description' => 'Rocket work']);

        $this->assertTrue($result['success']);
        $this->assertSame('Apollo', $result['project']['name']);
        $this->assertStringContainsString('/projects/', $result['url']);

        $project = Project::query()->firstOrFail();

        $this->assertSame('Apollo', $project->name);
        $this->assertSame('Rocket work', $project->description);
        $this->assertSame($this->workspace->id, $project->workspace_id);
    }

    public function test_the_creator_becomes_the_project_manager(): void
    {
        $this->create($this->owner, ['name' => 'Apollo']);

        $project = Project::query()->firstOrFail();

        $this->assertSame(ProjectRole::MANAGER, $project->roleFor($this->owner));
    }

    public function test_a_new_project_gets_the_three_default_board_columns(): void
    {
        $this->create($this->owner, ['name' => 'Apollo']);

        $columns = Project::query()->firstOrFail()->boardColumns()->orderBy('position')->pluck('name');

        $this->assertSame(['To Do', 'In Progress', 'Done'], $columns->all());
    }

    public function test_an_admin_can_create_a_project(): void
    {
        $admin = $this->memberOf(UserRole::ADMIN);

        $result = $this->create($admin, ['name' => 'Admin Project']);

        $this->assertTrue($result['success']);
        $this->assertSame(1, Project::query()->count());
    }

    public function test_a_plain_member_cannot_create_a_project(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $result = $this->create($member, ['name' => 'Nope']);

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertSame(0, Project::query()->count());
    }

    public function test_a_duplicate_name_in_the_same_workspace_is_rejected(): void
    {
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Apollo']);

        $result = $this->create($this->owner, ['name' => 'Apollo']);

        $this->assertFalse($result['success']);
        $this->assertSame('duplicate_name', $result['error_code']);
        $this->assertSame(1, Project::query()->count());
    }

    public function test_the_same_name_may_exist_in_a_different_workspace(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        Project::factory()->forWorkspace($other)->create(['name' => 'Apollo']);

        $result = $this->create($this->owner, ['name' => 'Apollo']);

        $this->assertTrue($result['success']);
        $this->assertSame($this->workspace->id, Project::query()->where('name', 'Apollo')->latest('id')->first()->workspace_id);
    }

    public function test_the_project_is_always_created_in_the_conversation_workspace(): void
    {
        $other = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $other->id])->save();

        $result = $this->create($this->owner, ['name' => 'Scoped']);

        $this->assertTrue($result['success']);
        $this->assertSame($this->workspace->id, Project::query()->firstOrFail()->workspace_id);
        $this->assertSame(0, $other->projects()->count());
    }

    public function test_the_tool_requires_confirmation(): void
    {
        $this->assertTrue(app(CreateProjectTool::class)->requiresConfirmation());
    }

    public function test_a_plain_member_is_not_offered_the_tool(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->contextFor($member)),
        );

        $this->assertNotContains('create_project', $names);
    }

    public function test_an_admin_is_offered_the_tool(): void
    {
        $admin = $this->memberOf(UserRole::ADMIN);

        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->contextFor($admin)),
        );

        $this->assertContains('create_project', $names);
    }

    public function test_the_tool_is_not_offered_without_a_workspace_context(): void
    {
        $this->assertFalse(app(CreateProjectTool::class)->authorize(new ToolContext($this->owner, null)));
    }

    public function test_a_missing_name_fails_schema_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate(app(CreateProjectTool::class), ['description' => 'orphan']);
    }

    public function test_a_too_short_name_fails_schema_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate(app(CreateProjectTool::class), ['name' => 'x']);
    }

    public function test_valid_arguments_pass_schema_validation(): void
    {
        $validated = app(ToolArgumentValidator::class)->validate(
            app(CreateProjectTool::class),
            ['name' => 'Apollo', 'workspace_id' => 999, 'injected' => 'dropped'],
        );

        $this->assertSame('Apollo', $validated['name']);
        $this->assertArrayNotHasKey('workspace_id', $validated);
        $this->assertArrayNotHasKey('injected', $validated);
    }

    public function test_creating_a_project_records_an_audit_entry(): void
    {
        $this->create($this->owner, ['name' => 'Audited']);

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'project_id' => Project::query()->firstOrFail()->id,
            'user_id' => $this->owner->id,
            'action' => 'project.created',
        ]);
    }
}
