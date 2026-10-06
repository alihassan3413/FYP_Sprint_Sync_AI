<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ?period=this_month|last_month|custom&from=&to= in the workspace's calendar.
 * Anything missing or invalid falls back to this month rather than failing,
 * so a bookmarked link never breaks. Authorization is the controller's.
 */
final class FinancePeriodRequest extends FormRequest
{
    private const MAX_DAYS = 1100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{key: string, from: CarbonImmutable, to: CarbonImmutable}
     */
    public function period(): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');
        $today = CarbonImmutable::now($workspace->timezone)->startOfDay();

        if ($this->query('period') === 'last_month') {
            $month = $today->startOfMonth()->subMonthNoOverflow();

            return ['key' => 'last_month', 'from' => $month, 'to' => $month->endOfMonth()->startOfDay()];
        }

        if ($this->query('period') === 'custom') {
            $from = $this->day($this->query('from'));
            $to = $this->day($this->query('to'));

            if ($from !== null && $to !== null && $from->lessThanOrEqualTo($to) && $from->diffInDays($to) <= self::MAX_DAYS) {
                return ['key' => 'custom', 'from' => $from, 'to' => $to];
            }
        }

        return ['key' => 'this_month', 'from' => $today->startOfMonth(), 'to' => $today->endOfMonth()->startOfDay()];
    }

    private function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
