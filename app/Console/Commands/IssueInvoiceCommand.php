<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Billing\Actions\IssueInvoiceAction;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use Illuminate\Console\Command;

/**
 * Development and testing only: issues an approved invoice through the real
 * IssueInvoiceAction (number, dates, official PDF) without sending any email,
 * because email delivery does not exist yet. Refuses to run in production.
 */
final class IssueInvoiceCommand extends Command
{
    protected $signature = 'invoices:issue
        {invoice : The invoice public id (the code at the end of its URL)}
        {--now : Skip the rest of the cancel window, like a future "Send now"}';

    protected $description = '[dev/testing only] Issue an approved invoice without sending email';

    public function handle(IssueInvoiceAction $action): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('invoices:issue only runs in local and testing environments.');

            return self::FAILURE;
        }

        $invoice = Invoice::query()->where('public_id', strtolower((string) $this->argument('invoice')))->first();

        if ($invoice === null) {
            $this->error('No invoice with that id.');

            return self::FAILURE;
        }

        try {
            $issued = $action->handle($invoice, null, (bool) $this->option('now'));
        } catch (InvoiceException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Issued {$issued->number} (no email was sent).");
        $this->line($issued->pdf_path !== null ? 'Official PDF stored.' : 'Official PDF will be written on first download.');

        return self::SUCCESS;
    }
}
