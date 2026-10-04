<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Development and QA only: runs every billing automation step as if it were
 * another moment ("2026-11-01 09:30"), so a month of recurring invoices can be
 * tested without waiting. The same commands the scheduler runs, in order.
 * Refuses to run in production.
 */
final class SimulateBillingAutomationCommand extends Command
{
    protected $signature = 'billing:simulate
        {at : The moment to pretend it is, e.g. "2026-11-01 09:30"}
        {--timezone=UTC : The timezone that moment is in (use the workspace\'s)}';

    protected $description = '[dev/QA only] Run recurring invoice automation as if it were another date';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('billing:simulate only runs in local and testing environments.');

            return self::FAILURE;
        }

        $at = CarbonImmutable::parse((string) $this->argument('at'), (string) $this->option('timezone'));
        /* The app clock runs in UTC; a local-time "now" would be stored shifted. */
        Carbon::setTestNow($at->utc());
        CarbonImmutable::setTestNow($at->utc());
        $this->info("Pretending it is {$at->toDayDateTimeString()} ({$at->timezoneName}).");

        foreach (['billing:generate-recurring-invoices', 'billing:process-recurring-invoices', 'billing:send-owner-reminders', 'billing:dispatch-approved-invoices'] as $command) {
            $this->line("→ {$command}");
            $this->call($command);
        }

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        return self::SUCCESS;
    }
}
