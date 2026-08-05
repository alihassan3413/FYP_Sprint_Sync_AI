<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Exceptions\AssistantQuotaException;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Support\UsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssistantUsageGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_user_has_spent_nothing(): void
    {
        $this->assertSame(0, app(UsageGuard::class)->spentTodayCents(User::factory()->create()));
    }

    public function test_todays_token_usage_is_converted_to_cents(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        Message::factory()
            ->assistantUsage('gpt-4o-mini', 1_000_000, 1_000_000)
            ->create(['conversation_id' => $conversation->id]);

        $this->assertSame(75, app(UsageGuard::class)->spentTodayCents($user));
    }

    public function test_yesterdays_usage_does_not_count(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        Message::factory()
            ->assistantUsage('gpt-4o-mini', 1_000_000, 1_000_000)
            ->create([
                'conversation_id' => $conversation->id,
                'created_at' => now()->subDay(),
            ]);

        $this->assertSame(0, app(UsageGuard::class)->spentTodayCents($user));
    }

    public function test_another_users_usage_does_not_count(): void
    {
        $user = User::factory()->create();
        $other = Conversation::factory()->create(['user_id' => User::factory()->create()->id]);

        Message::factory()
            ->assistantUsage('gpt-4o', 1_000_000, 1_000_000)
            ->create(['conversation_id' => $other->id]);

        $this->assertSame(0, app(UsageGuard::class)->spentTodayCents($user));
    }

    public function test_the_daily_cap_blocks_further_requests(): void
    {
        config(['assistant.cost_caps.free_tier_daily_cents' => 10]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        Message::factory()
            ->assistantUsage('gpt-4o-mini', 1_000_000, 0)
            ->create(['conversation_id' => $conversation->id]);

        $this->expectException(AssistantQuotaException::class);

        app(UsageGuard::class)->ensureWithinDailyBudget($user);
    }

    public function test_the_chat_endpoint_returns_429_when_the_cap_is_hit(): void
    {
        config(['assistant.cost_caps.free_tier_daily_cents' => 1]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        Message::factory()
            ->assistantUsage('gpt-4o', 1_000_000, 1_000_000)
            ->create(['conversation_id' => $conversation->id]);

        $this->actingAs($user)
            ->postJson(route('assistant.chat'), ['message' => 'hello'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'ASSISTANT.QUOTA.EXCEEDED');
    }
}
