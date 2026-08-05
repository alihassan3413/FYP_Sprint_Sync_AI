<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssistantEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_reach_the_chat_endpoint(): void
    {
        $this->postJson(route('assistant.chat'), ['message' => 'hello'])->assertUnauthorized();
    }

    public function test_a_conversation_belonging_to_another_user_is_rejected(): void
    {
        $owner = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.chat'), [
                'message' => 'hello',
                'conversation_id' => $conversation->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('conversation_id');
    }

    public function test_a_workspace_the_user_does_not_belong_to_is_rejected(): void
    {
        $workspace = Workspace::factory()->ownedBy(User::factory()->create())->create();

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.chat'), [
                'message' => 'hello',
                'workspace_id' => $workspace->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('workspace_id');
    }

    public function test_an_unsupported_model_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.chat'), [
                'message' => 'hello',
                'model' => 'gpt-5-ultra',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('model');
    }

    public function test_another_users_pending_action_cannot_be_confirmed(): void
    {
        $owner = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $owner->id]);

        $pending = Message::factory()
            ->pendingTool('create_workspace', ['name' => 'Theirs'])
            ->create(['conversation_id' => $conversation->id]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.confirm'), [
                'message_id' => $pending->id,
                'action' => 'confirm',
            ])
            ->assertNotFound();

        $this->assertSame(Message::STATUS_PENDING, $pending->refresh()->tool_status);
    }

    public function test_chat_is_rate_limited(): void
    {
        config(['assistant.rate_limits.per_minute' => 2]);

        $user = User::factory()->create();

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($user)->postJson(route('assistant.chat'), ['message' => '']);
        }

        $this->actingAs($user)
            ->postJson(route('assistant.chat'), ['message' => 'hello'])
            ->assertStatus(429);
    }
}
