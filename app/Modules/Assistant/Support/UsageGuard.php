<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Models\User;
use App\Modules\Assistant\Exceptions\AssistantQuotaException;
use App\Modules\Assistant\Models\Message;

final class UsageGuard
{
    public function ensureWithinDailyBudget(User $user): void
    {
        $limit = $this->dailyLimitCents($user);
        $spent = $this->spentTodayCents($user);

        if ($spent >= $limit) {
            throw AssistantQuotaException::dailyCostExceeded($spent, $limit);
        }
    }

    public function spentTodayCents(User $user): int
    {
        $usage = Message::query()
            ->whereHas('conversation', fn ($query) => $query->where('user_id', $user->id))
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('model, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
            ->groupBy('model')
            ->get();

        $total = 0.0;

        foreach ($usage as $row) {
            $pricing = $this->pricingFor((string) $row->model);

            $total += ($row->input_tokens / 1_000_000) * $pricing['input']
                + ($row->output_tokens / 1_000_000) * $pricing['output'];
        }

        return (int) ceil($total);
    }

    public function dailyLimitCents(User $user): int
    {
        return (int) config('assistant.cost_caps.free_tier_daily_cents');
    }

    /**
     * @return array{input: float, output: float}
     */
    private function pricingFor(string $model): array
    {
        $pricing = config('assistant.pricing');

        return $pricing[$model] ?? $pricing['default'];
    }
}
