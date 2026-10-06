<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Models\Invoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

/**
 * The official PDF of an issued invoice: generated once, stored privately,
 * and served unchanged forever after.
 *
 * Files and database rows cannot share a transaction, so the order is:
 * 1. the invoice is issued (the database commit is the financial truth);
 * 2. the PDF is rendered from the frozen snapshot and written to a path that
 *    only depends on the invoice (…/invoices/{public_id}/official.pdf);
 * 3. pdf_path is recorded with a conditional update (only while still null).
 *
 * A crash between any of these steps leaves an issued invoice without
 * pdf_path; the next ensure() (another issue attempt or the first download)
 * finishes the job. A lock serialises concurrent attempts, and an existing
 * file is reused rather than overwritten, so there is only ever one official
 * document per invoice.
 */
final class OfficialInvoicePdf
{
    public const DISK = 'local';

    public function __construct(private readonly InvoicePdfRenderer $renderer) {}

    public function path(Invoice $invoice): string
    {
        return "workspaces/{$invoice->workspace_id}/invoices/{$invoice->public_id}/official.pdf";
    }

    /**
     * Returns the stored path, writing the document first if needed.
     */
    public function ensure(Invoice $invoice): string
    {
        if (! $invoice->isIssued()) {
            throw new LogicException('Only issued invoices have an official PDF.');
        }

        $disk = Storage::disk(self::DISK);

        if ($invoice->pdf_path !== null && $disk->exists($invoice->pdf_path)) {
            return $invoice->pdf_path;
        }

        return Cache::lock("invoice-pdf:{$invoice->id}", 60)->block(30, function () use ($invoice, $disk) {
            $invoice->refresh();
            $path = $this->path($invoice);

            if (! $disk->exists($path)) {
                $disk->put($path, $this->renderer->pdf($invoice));
            }

            Invoice::query()->whereKey($invoice->getKey())->whereNull('pdf_path')->update(['pdf_path' => $path]);

            return $path;
        });
    }

    /**
     * ensure() for the issuing step: a failure is reported, not thrown, because
     * the invoice is already issued; the document is written on the next try.
     */
    public function ensureSafely(Invoice $invoice): void
    {
        try {
            $this->ensure($invoice);
        } catch (Throwable $exception) {
            Log::error('Could not write the official PDF for an issued invoice; it will be retried on the next attempt.', [
                'invoice' => $invoice->public_id,
                'exception' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }
}
