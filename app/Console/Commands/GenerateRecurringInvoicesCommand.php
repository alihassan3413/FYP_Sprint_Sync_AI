<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Billing\Actions\GenerateDueRecurringInvoiceAction;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\BillingPlan;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Prepares each active recurring invoice's due month. Safe to run as often as
 * the scheduler likes: generation is unique per plan and month.
 */
final class GenerateRecurringInvoicesCommand extends Command
{
    protected $signature = 'billing:generate-recurring-invoices';

    protected $description = 'Prepare recurring invoices whose generation day has arrived';

    public function handle(GenerateDueRecurringInvoiceAction $generate): int
    {
        $now = CarbonImmutable::now();
        $created = 0;

        BillingPlan::query()->whereNull('paused_at')->with(['workspace', 'client'])->lazyById()->each(function (BillingPlan $plan) use ($generate, $now, &$created) {
            try {
                $created += (int) ($generate->handle($plan, $now)?->wasRecentlyCreated ?? false);
            } catch (InvoiceException $exception) {
                $this->warn("{$plan->name}: {$exception->getMessage()}");
            }
        });

        $this->info("Prepared {$created} invoice(s).");

        return self::SUCCESS;
    }
}
