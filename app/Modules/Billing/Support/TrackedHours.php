<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Models\Invoice;
use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Data\TimeQuery;
use Carbon\CarbonImmutable;

/**
 * Recorded time for an hourly invoice, read only through TimeTotals: billable
 * work by each person, on the projects the recurring invoice bills, in the
 * invoice's period, including paid lunch within the allowance (and excess the
 * owner counted). Never unpaid pauses, holidays, leave or non-billable time.
 *
 * Time is integer seconds; invoices count hundredths of an hour. One
 * hundredth is exactly 36 seconds, and the conversion rounds half up:
 * 0–17 s → 0.00 h, 18–53 s → 0.01 h, 576 900 s (160h 15m) → 160.25 h.
 */
final class TrackedHours
{
    public function __construct(private readonly TimeTotals $totals) {}

    public static function centi(int $seconds): int
    {
        return intdiv(max(0, $seconds) + 18, 36);
    }

    /** Does this invoice take its hours from time tracking? */
    public static function applies(Invoice $invoice): bool
    {
        return ! empty($invoice->time_project_ids);
    }

    public function secondsFor(Invoice $invoice, int $personId): int
    {
        return $this->totals->seconds(new TimeQuery(
            $invoice->workspace_id,
            personId: $personId,
            from: CarbonImmutable::parse($invoice->period_start->toDateString()),
            to: CarbonImmutable::parse($invoice->period_end->toDateString()),
            billableOnly: true,
            projectIds: array_map('intval', $invoice->time_project_ids ?? []),
        ));
    }
}
