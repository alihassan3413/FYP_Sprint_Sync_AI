<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Data\AssistantUsageData;
use App\Modules\Admin\Data\ModelUsageData;
use App\Modules\Admin\Data\TopAssistantUserData;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide assistant consumption.
 *
 * Cost is derived from token counts and the per-model rates in
 * config/assistant.php, the same source the per-user daily cap uses, so the
 * two never disagree about what a conversation cost.
 */
final class BuildAssistantUsageReport
{
    private const TOP_USER_LIMIT = 10;

    public function handle(): AssistantUsageData
    {
        $byModel = $this->usageByModel();

        return new AssistantUsageData(
            conversations_total: Conversation::query()->count(),
            messages_total: Message::query()->count(),
            input_tokens: (int) array_sum(array_map(fn (ModelUsageData $m) => $m->input_tokens, $byModel)),
            output_tokens: (int) array_sum(array_map(fn (ModelUsageData $m) => $m->output_tokens, $byModel)),
            estimated_cost_cents: (int) array_sum(array_map(fn (ModelUsageData $m) => $m->estimated_cost_cents, $byModel)),
            cost_today_cents: $this->costToday(),
            by_model: $byModel,
            top_users: $this->topUsers(),
        );
    }

    /**
     * @return array<int, ModelUsageData>
     */
    private function usageByModel(): array
    {
        return Message::query()
            ->whereNotNull('model')
            ->selectRaw('model, provider, COUNT(*) as messages, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
            ->groupBy('model', 'provider')
            ->orderByDesc('messages')
            ->get()
            ->map(fn ($row) => new ModelUsageData(
                model: (string) $row->model,
                provider: $row->provider,
                messages: (int) $row->messages,
                input_tokens: (int) $row->input_tokens,
                output_tokens: (int) $row->output_tokens,
                estimated_cost_cents: $this->costCents((string) $row->model, (int) $row->input_tokens, (int) $row->output_tokens),
            ))
            ->all();
    }

    /**
     * @return array<int, TopAssistantUserData>
     */
    private function topUsers(): array
    {
        return DB::table('assistant_messages')
            ->join('assistant_conversations', 'assistant_messages.conversation_id', '=', 'assistant_conversations.id')
            ->join('users', 'assistant_conversations.user_id', '=', 'users.id')
            ->selectRaw('users.id, users.name, users.email, assistant_messages.model')
            ->selectRaw('COUNT(*) as messages')
            ->selectRaw('SUM(assistant_messages.input_tokens) as input_tokens')
            ->selectRaw('SUM(assistant_messages.output_tokens) as output_tokens')
            ->groupBy('users.id', 'users.name', 'users.email', 'assistant_messages.model')
            ->get()
            // Grouped by model as well as user so each slice is priced at its
            // own rate, then folded back down to one row per user.
            ->groupBy('id')
            ->map(function ($rows) {
                $first = $rows->first();

                return new TopAssistantUserData(
                    id: (int) $first->id,
                    name: (string) $first->name,
                    email: (string) $first->email,
                    messages: (int) $rows->sum('messages'),
                    input_tokens: (int) $rows->sum('input_tokens'),
                    output_tokens: (int) $rows->sum('output_tokens'),
                    estimated_cost_cents: (int) $rows->sum(fn ($row) => $this->costCents(
                        (string) ($row->model ?? ''),
                        (int) $row->input_tokens,
                        (int) $row->output_tokens,
                    )),
                );
            })
            ->sortByDesc(fn (TopAssistantUserData $user) => $user->messages)
            ->take(self::TOP_USER_LIMIT)
            ->values()
            ->all();
    }

    private function costToday(): int
    {
        $rows = Message::query()
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('model, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
            ->groupBy('model')
            ->get();

        $total = 0;

        foreach ($rows as $row) {
            $total += $this->costCents((string) ($row->model ?? ''), (int) $row->input_tokens, (int) $row->output_tokens);
        }

        return $total;
    }

    /**
     * Rates in config are cents per million tokens.
     */
    private function costCents(string $model, int $inputTokens, int $outputTokens): int
    {
        $pricing = config('assistant.pricing');
        $rate = $pricing[$model] ?? $pricing['default'];

        return (int) ceil(
            ($inputTokens / 1_000_000) * $rate['input']
            + ($outputTokens / 1_000_000) * $rate['output']
        );
    }
}
