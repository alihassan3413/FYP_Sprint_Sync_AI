<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Contracts\ExchangeRateProvider;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\FxRate;
use App\Modules\Billing\Data\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Converts one financial record's amount into a reporting currency, exactly:
 * minor units × rate (BigDecimal), rounded half up once, to whole minor
 * units. Never a float.
 *
 * Historical stability: the first time a record is converted into a
 * currency, the rate used is stored (fx_snapshots). Every later report reuses
 * that snapshot, so entering a different rate afterwards never changes a
 * past result. The record's own amount and currency are never touched.
 *
 * Returns null when no rate applies on the record's date (nothing guessed).
 */
final class CurrencyConverter
{
    public function __construct(private readonly ExchangeRateProvider $rates) {}

    public static function convertMinor(int $minor, FxRate $rate): int
    {
        /* Shift by the difference in minor units (both 2 today); the decimal point just moves, still exact. */
        $value = BigDecimal::of($minor)
            ->multipliedBy($rate->decimal())
            ->withPointMovedRight($rate->to->minorUnits() - $rate->from->minorUnits());

        return $value->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    /**
     * @param  string  $subjectType  a short stable name ("expense", "invoice", "payment")
     */
    public function convert(int $workspaceId, string $subjectType, int $subjectId, Money $amount, Currency $to, CarbonImmutable $date): ?Money
    {
        if ($amount->currency === $to) {
            return $amount;
        }

        $snapshot = DB::table('fx_snapshots')->where('subject_type', $subjectType)->where('subject_id', $subjectId)->where('to_currency', $to->value)->first();

        if ($snapshot !== null) {
            $rate = new FxRate($amount->currency, $to, $snapshot->rate, (string) $snapshot->rate_date, $snapshot->source);
        } else {
            $rate = $this->rates->rate($workspaceId, $amount->currency, $to, $date);

            if ($rate === null) {
                return null;
            }

            try {
                DB::table('fx_snapshots')->insert([
                    'workspace_id' => $workspaceId,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'from_currency' => $amount->currency->value,
                    'to_currency' => $to->value,
                    'rate' => $rate->rate,
                    'rate_date' => $rate->rateDate,
                    'source' => $rate->source,
                    'exchange_rate_id' => $rate->exchangeRateId,
                    'captured_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                /* Captured by a concurrent request: use that one. */
                return $this->convert($workspaceId, $subjectType, $subjectId, $amount, $to, $date);
            }
        }

        return Money::of(self::convertMinor($amount->minor, $rate), $to);
    }
}
