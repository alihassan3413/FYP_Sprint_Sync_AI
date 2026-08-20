<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Support\CommandCatalog;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssistantCommandCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user->refresh();
    }

    public function test_every_registered_tool_has_catalogue_copy(): void
    {
        $registered = array_map(fn ($tool) => $tool->name(), app(ToolRegistry::class)->all());
        $described = CommandCatalog::describedToolNames();

        $missing = array_diff($registered, $described);

        $this->assertSame(
            [],
            array_values($missing),
            'A tool was registered without user-facing command copy: '.implode(', ', $missing),
        );
    }

    public function test_the_catalogue_never_describes_a_tool_that_does_not_exist(): void
    {
        $registered = array_map(fn ($tool) => $tool->name(), app(ToolRegistry::class)->all());

        $this->assertSame([], array_values(array_diff(CommandCatalog::describedToolNames(), $registered)));
    }

    public function test_guests_cannot_read_the_command_list(): void
    {
        $this->getJson(route('assistant.commands'))->assertUnauthorized();
    }

    public function test_an_owner_sees_every_command(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson(route('assistant.commands'))
            ->assertOk();

        $names = array_column($response->json('commands'), 'name');

        $this->assertContains('invite_user', $names);
        $this->assertContains('create_task', $names);
        $this->assertContains('get_analytics', $names);
        $this->assertSame($this->workspace->id, $response->json('workspace_id'));
    }

    public function test_a_member_is_not_offered_commands_they_cannot_run(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $names = array_column(
            $this->actingAs($member)->getJson(route('assistant.commands'))->assertOk()->json('commands'),
            'name',
        );

        $this->assertNotContains('invite_user', $names, 'A member cannot invite, so the command must not be listed.');
        $this->assertContains('find_tasks', $names);
    }

    public function test_a_client_is_not_offered_analytics(): void
    {
        $client = $this->memberOf(UserRole::CLIENT);
        $project = Project::factory()->forWorkspace($this->workspace)->create();
        $project->members()->attach($client->id, ['role' => ProjectRole::MEMBER->value]);

        $names = array_column(
            $this->actingAs($client->refresh())->getJson(route('assistant.commands'))->assertOk()->json('commands'),
            'name',
        );

        $this->assertNotContains('get_analytics', $names);
    }

    public function test_every_command_carries_the_copy_the_picker_renders(): void
    {
        $commands = $this->actingAs($this->owner)->getJson(route('assistant.commands'))->assertOk()->json('commands');

        $this->assertNotEmpty($commands);

        foreach ($commands as $command) {
            $this->assertNotSame('', $command['label']);
            $this->assertNotSame('', $command['description']);
            $this->assertNotSame('', $command['category']);
            $this->assertNotSame('', $command['template']);
            $this->assertNotEmpty($command['keywords']);
            $this->assertIsBool($command['requires_confirmation']);
        }
    }

    public function test_the_confirmation_flag_matches_the_registered_tool(): void
    {
        $commands = collect($this->actingAs($this->owner)->getJson(route('assistant.commands'))->json('commands'))
            ->keyBy('name');

        foreach (app(ToolRegistry::class)->all() as $tool) {
            $command = $commands->get($tool->name());

            if ($command === null) {
                continue;
            }

            $this->assertSame(
                $tool->requiresConfirmation(),
                $command['requires_confirmation'],
                "The picker would mislabel {$tool->name()}.",
            );
        }
    }

    public function test_a_workspace_the_user_does_not_belong_to_is_rejected(): void
    {
        $foreign = Workspace::factory()->ownedBy(User::factory()->create())->create();

        $this->actingAs($this->owner)
            ->getJson(route('assistant.commands', ['workspace_id' => $foreign->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('workspace_id');
    }

    public function test_another_users_conversation_is_rejected(): void
    {
        $conversation = Conversation::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($this->owner)
            ->getJson(route('assistant.commands', ['conversation_id' => $conversation->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('conversation_id');
    }

    public function test_the_conversation_workspace_decides_what_is_listed(): void
    {
        $other = Workspace::factory()->ownedBy($this->owner)->create();
        $conversation = Conversation::factory()->create([
            'user_id' => $this->owner->id,
            'workspace_id' => $other->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson(route('assistant.commands', ['conversation_id' => $conversation->id]))
            ->assertOk();

        $this->assertSame($other->id, $response->json('workspace_id'));
    }

    public function test_a_user_with_no_workspace_still_gets_a_usable_list(): void
    {
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)->getJson(route('assistant.commands'))->assertOk();

        $this->assertNull($response->json('workspace_id'));
        $this->assertContains('create_workspace', array_column($response->json('commands'), 'name'));
    }
}
