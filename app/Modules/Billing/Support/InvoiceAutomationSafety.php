<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Data\AutomationReason;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Data\SafetyResult;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Decides whether an auto-send invoice may be approved without a person.
 * Read-only: it never changes anything, so it can be called any number of
 * times. Every rule adds a plain-language reason; any reason means "review".
 *
 * Comparisons are against the most recent ISSUED invoice of the same
 * recurring plan (in the same currency) for an earlier month: never another
 * client or plan. All maths is integer (minor units, hundredths of hours):
 * "changed by more than P%" is |now - before| * 100 > P * before, so there
 * is no division and no float. Exactly P% is allowed.
 *
 * When "before" is zero, any non-zero amount is treated as unusual (there is
 * no percentage to compare against, so a person should look).
 */
final class InvoiceAutomationSafety
{
    /** Totals may move by up to 25% month to month. */
    public const TOTAL_VARIANCE_PERCENT = 25;

    /** Each person's hours may move by up to 30%. */
    public const HOURS_VARIANCE_PERCENT = 30;

    public const FIRST_INVOICE_MESSAGE = 'First invoice — review it before automatic sending starts.';

    public function evaluate(Invoice $invoice, CarbonImmutable $today): SafetyResult
    {
        $invoice->loadMissing(['lines', 'workspace.mailSettings', 'workspace.invoicingProfile']);
        $reasons = [];
        $add = function (AutomationReason $code, string $message) use (&$reasons) {
            $reasons[] = ['code' => $code->value, 'message' => $message];
        };

        if ($today->toDateString() > $invoice->planned_send_on->toDateString()) {
            $add(AutomationReason::SendDateMissed, 'The scheduled send date passed before SprintSync could check this invoice. Review and send it.');
        }

        if ($invoice->status === InvoiceStatus::NeedsHours) {
            $missing = $invoice->lines->whereNull('quantity_centi')->count();
            $add(AutomationReason::MissingHours, "{$invoice->title} still needs hours before it can be sent ({$missing} ".($missing === 1 ? 'person' : 'people').' missing).');
        }

        $mail = $invoice->workspace->mailSettings;
        if ($mail === null || $mail->verified_at === null) {
            $add(AutomationReason::MailNotConnected, 'Connect email delivery before this invoice can be sent automatically.');
        }

        $sender = $invoice->bill_from ?? SenderSnapshot::from($invoice->workspace->invoicingProfile);
        if (! SenderSnapshot::isComplete($sender)) {
            $add(AutomationReason::SenderIncomplete, 'Complete your invoicing details before this invoice can be sent automatically.');
        }

        if (! filter_var($invoice->bill_to['billing_email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            $add(AutomationReason::RecipientInvalid, "The client's billing email is missing or not valid.");
        }

        $previous = $this->previousIssued($invoice);

        if ($previous === null) {
            $add(AutomationReason::FirstInvoice, self::FIRST_INVOICE_MESSAGE);

            return new SafetyResult($reasons);
        }

        if ($invoice->status !== InvoiceStatus::NeedsHours) {
            if ($message = $this->totalChange($previous->total_minor, $invoice->total_minor)) {
                $add(AutomationReason::TotalVariance, $message);
            }

            if ($invoice->pricing_mode === PricingMode::Hourly) {
                foreach ($this->hoursChanges($previous, $invoice) as $message) {
                    $add(AutomationReason::HoursVariance, $message);
                }
            }
        }

        return new SafetyResult($reasons);
    }

    /** The comparable invoice: same plan, same currency, issued, for an earlier month. */
    public function previousIssued(Invoice $invoice): ?Invoice
    {
        if ($invoice->billing_plan_id === null) {
            return null;
        }

        return Invoice::query()
            ->with('lines')
            ->where('billing_plan_id', $invoice->billing_plan_id)
            ->where('status', InvoiceStatus::Issued->value)
            ->where('currency', $invoice->currency->value)
            ->whereDate('period_start', '<', $invoice->period_start->toDateString())
            ->orderByDesc('period_start')
            ->first();
    }

    private function totalChange(int $before, int $now): ?string
    {
        if (! $this->changedTooMuch($before, $now, self::TOTAL_VARIANCE_PERCENT)) {
            return null;
        }

        if ($before === 0) {
            return 'Last month\'s total was zero, so this total needs a look.';
        }

        $direction = $now > $before ? 'higher' : 'lower';

        return "Total is {$this->percent($before, $now, self::TOTAL_VARIANCE_PERCENT)} {$direction} than last month.";
    }

    /**
     * People are matched by their team profile when the line still has one,
     * otherwise by the name printed on the line; never by row position.
     * Someone new, or someone who disappeared, needs a look too.
     *
     * @return list<string>
     */
    private function hoursChanges(Invoice $previous, Invoice $current): array
    {
        $before = $this->hoursByPerson($previous->lines);
        $now = $this->hoursByPerson($current->lines);
        $messages = [];

        foreach ($now as $key => ['name' => $name, 'centi' => $centi]) {
            if (! isset($before[$key])) {
                if ($centi > 0) {
                    $messages[] = "{$name} wasn't on last month's invoice.";
                }

                continue;
            }

            $was = $before[$key]['centi'];

            if (! $this->changedTooMuch($was, $centi, self::HOURS_VARIANCE_PERCENT)) {
                continue;
            }

            $messages[] = $was === 0
                ? "{$name} had no hours last month."
                : "{$name}'s hours are {$this->percent($was, $centi, self::HOURS_VARIANCE_PERCENT)} ".($centi > $was ? 'higher' : 'lower').' than last month.';
        }

        foreach (array_diff_key($before, $now) as ['name' => $name, 'centi' => $centi]) {
            if ($centi > 0) {
                $messages[] = "{$name} was on last month's invoice but isn't on this one.";
            }
        }

        return $messages;
    }

    /**
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array<string, array{name: string, centi: int}>
     */
    private function hoursByPerson(Collection $lines): array
    {
        $people = [];

        foreach ($lines as $line) {
            $key = $line->person_id !== null ? "person:{$line->person_id}" : 'name:'.mb_strtolower(trim($line->description));
            $people[$key] ??= ['name' => $line->description, 'centi' => 0];
            $people[$key]['centi'] += (int) $line->quantity_centi;
        }

        return $people;
    }

    private function changedTooMuch(int $before, int $now, int $percent): bool
    {
        return abs($now - $before) * 100 > $percent * $before;
    }

    /**
     * "38%": the change rounded half-up. A change just over the limit that
     * rounds down to it reads "more than 25%", never "25%".
     */
    private function percent(int $before, int $now, int $limit): string
    {
        $rounded = intdiv(abs($now - $before) * 200 + $before, 2 * $before);

        return $rounded <= $limit ? "more than {$limit}%" : "{$rounded}%";
    }
}
