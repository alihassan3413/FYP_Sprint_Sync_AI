<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Models\ExchangeRate;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * An owner-entered rate: 1 {base} = {rate} {quote}, from {rate_date}. Rates
 * are never edited; a newer rate simply takes over from its date. Totals
 * already converted keep the rate they were converted with.
 */
final class EnterExchangeRateAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Workspace $workspace, Currency $base, Currency $quote, string $rate, CarbonImmutable $rateDate, User $actor): ExchangeRate
    {
        $exchangeRate = ExchangeRate::query()->forceCreate([
            'workspace_id' => $workspace->id,
            'base_currency' => $base,
            'quote_currency' => $quote,
            'rate' => $rate,
            'rate_date' => $rateDate->toDateString(),
            'source' => 'manual',
            'created_by' => $actor->id,
        ]);

        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::FX_RATE_ENTERED,
            "{$actor->name} entered an exchange rate: 1 {$base->value} = {$rate} {$quote->value} from {$rateDate->toDateString()}.",
            $exchangeRate,
            ['base' => $base->value, 'quote' => $quote->value, 'rate' => $rate, 'rate_date' => $rateDate->toDateString()],
        );

        return $exchangeRate;
    }
}
