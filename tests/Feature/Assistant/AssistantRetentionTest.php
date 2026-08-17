<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Actions\PruneAssistantConversations;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Support\ToolResultEnvelope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssistantRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config([
            'assistant.retention.conversation_days' => 30,
            'assistant.retention.tool_result_days' => 7,
        ]);
    }

    private function conversationAgedDays(int $days): Conversation
    {
        $conversation = Conversation::create(['user_id' => $this->user->id]);

        $conversation->forceFill([
            'created_at' => now()->subDays($days),
            'updated_at' => now()->subDays($days),
        ])->save();

        return $conversation;
    }

    private function toolMessage(Conversation $conversation, int $ageInDays, string $payload = 'ada@example.com'): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'tool',
            'tool_call_id' => 'call_'.uniqid(),
            'tool_status' => Message::STATUS_EXECUTED,
            'content' => ToolResultEnvelope::wrap(['success' => true, 'email' => $payload]),
            'metadata' => ['name' => 'get_workspace_info'],
        ]);

        $message->forceFill([
            'created_at' => now()->subDays($ageInDays),
            'updated_at' => now()->subDays($ageInDays),
        ])->save();

        return $message;
    }

    private function prune(): array
    {
        return app(PruneAssistantConversations::class)->handle();
    }

    public function test_a_conversation_past_the_window_is_deleted(): void
    {
        $stale = $this->conversationAgedDays(31);

        $result = $this->prune();

        $this->assertSame(1, $result['conversations_deleted']);
        $this->assertDatabaseMissing('assistant_conversations', ['id' => $stale->id]);
    }

    public function test_a_recent_conversation_is_kept(): void
    {
        $fresh = $this->conversationAgedDays(3);

        $result = $this->prune();

        $this->assertSame(0, $result['conversations_deleted']);
        $this->assertDatabaseHas('assistant_conversations', ['id' => $fresh->id]);
    }

    public function test_deleting_a_conversation_removes_its_messages(): void
    {
        $stale = $this->conversationAgedDays(45);
        $this->toolMessage($stale, 45);

        Message::create([
            'conversation_id' => $stale->id,
            'role' => 'user',
            'content' => 'my private question',
        ]);

        $result = $this->prune();

        $this->assertSame(2, $result['messages_deleted']);
        $this->assertSame(0, Message::query()->where('conversation_id', $stale->id)->count());
    }

    public function test_an_aged_tool_result_is_redacted_in_a_live_conversation(): void
    {
        $conversation = $this->conversationAgedDays(1);
        $aged = $this->toolMessage($conversation, 10);

        $result = $this->prune();

        $this->assertSame(1, $result['tool_results_redacted']);
        $this->assertSame(0, $result['conversations_deleted']);

        $content = $aged->refresh()->content;

        $this->assertStringNotContainsString('ada@example.com', $content);
        $this->assertStringContainsString('"redacted":true', $content);
    }

    public function test_a_redacted_tool_result_keeps_the_untrusted_envelope_shape(): void
    {
        $conversation = $this->conversationAgedDays(1);
        $aged = $this->toolMessage($conversation, 10);

        $this->prune();

        $decoded = json_decode($aged->refresh()->content, true);

        $this->assertSame(ToolResultEnvelope::NOTICE, $decoded['notice']);
        $this->assertTrue($decoded['result']['redacted']);
        $this->assertSame(PruneAssistantConversations::REDACTION_REASON, $decoded['result']['reason']);
    }

    public function test_a_recent_tool_result_is_left_alone(): void
    {
        $conversation = $this->conversationAgedDays(1);
        $recent = $this->toolMessage($conversation, 2);

        $result = $this->prune();

        $this->assertSame(0, $result['tool_results_redacted']);
        $this->assertStringContainsString('ada@example.com', $recent->refresh()->content);
    }

    public function test_redaction_is_idempotent(): void
    {
        $conversation = $this->conversationAgedDays(1);
        $this->toolMessage($conversation, 10);

        $this->assertSame(1, $this->prune()['tool_results_redacted']);
        $this->assertSame(0, $this->prune()['tool_results_redacted']);
    }

    public function test_user_and_assistant_messages_are_never_redacted(): void
    {
        $conversation = $this->conversationAgedDays(1);

        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'invite ada@example.com',
        ]);
        $userMessage->forceFill(['created_at' => now()->subDays(20)])->save();

        $assistantMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Invited ada@example.com.',
        ]);
        $assistantMessage->forceFill(['created_at' => now()->subDays(20)])->save();

        $this->prune();

        $this->assertStringContainsString('ada@example.com', $userMessage->refresh()->content);
        $this->assertStringContainsString('ada@example.com', $assistantMessage->refresh()->content);
    }

    public function test_one_users_retention_never_touches_another_users_recent_conversation(): void
    {
        $stale = $this->conversationAgedDays(60);

        $otherUser = User::factory()->create();
        $otherConversation = Conversation::create(['user_id' => $otherUser->id]);

        $this->prune();

        $this->assertDatabaseMissing('assistant_conversations', ['id' => $stale->id]);
        $this->assertDatabaseHas('assistant_conversations', ['id' => $otherConversation->id]);
    }

    public function test_a_zero_window_disables_that_side_of_the_prune(): void
    {
        $stale = $this->conversationAgedDays(90);
        $aged = $this->toolMessage($stale, 90);

        $result = app(PruneAssistantConversations::class)->handle(0, 0);

        $this->assertSame(0, $result['conversations_deleted']);
        $this->assertSame(0, $result['tool_results_redacted']);
        $this->assertDatabaseHas('assistant_conversations', ['id' => $stale->id]);
        $this->assertStringContainsString('ada@example.com', $aged->refresh()->content);
    }

    public function test_explicit_windows_override_the_configured_ones(): void
    {
        $conversation = $this->conversationAgedDays(5);

        $result = app(PruneAssistantConversations::class)->handle(3, 30);

        $this->assertSame(1, $result['conversations_deleted']);
        $this->assertDatabaseMissing('assistant_conversations', ['id' => $conversation->id]);
    }

    public function test_the_prune_command_reports_what_it_removed(): void
    {
        $stale = $this->conversationAgedDays(40);
        $this->toolMessage($stale, 40);

        $live = $this->conversationAgedDays(1);
        $this->toolMessage($live, 10);

        $this->artisan('assistant:prune')
            ->expectsOutputToContain('Deleted 1 conversation(s) and 1 message(s); redacted 1 tool result(s).')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('assistant_conversations', ['id' => $stale->id]);
        $this->assertDatabaseHas('assistant_conversations', ['id' => $live->id]);
    }

    public function test_the_prune_command_accepts_window_overrides(): void
    {
        $conversation = $this->conversationAgedDays(5);

        $this->artisan('assistant:prune', ['--conversation-days' => 3])->assertExitCode(0);

        $this->assertDatabaseMissing('assistant_conversations', ['id' => $conversation->id]);
    }

    public function test_the_prune_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'assistant:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('15 3 * * *', $events->first()->expression);
    }
}
