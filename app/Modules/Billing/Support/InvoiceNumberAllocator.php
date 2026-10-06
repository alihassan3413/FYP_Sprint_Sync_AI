<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Hands out the next business number (INV-2026-0005) for a workspace and year.
 *
 * Must run inside the approving transaction. The counter row is created if
 * missing (INSERT OR IGNORE), then incremented with a single UPDATE, which
 * takes the database write lock: two approvals can never read the same value,
 * and if the approval rolls back, so does the increment. UNIQUE(workspace_id,
 * number) on invoices is the final guarantee. A number, once committed, is
 * never handed out again, even if that invoice is later voided.
 */
final class InvoiceNumberAllocator
{
    public function next(Workspace $workspace, int $year): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Invoice numbers must be allocated inside the approving transaction.');
        }

        $counter = DB::table('invoice_number_sequences')->where('workspace_id', $workspace->id)->where('year', $year);

        DB::table('invoice_number_sequences')->insertOrIgnore(['workspace_id' => $workspace->id, 'year' => $year, 'last_number' => 0]);
        (clone $counter)->increment('last_number');

        return sprintf('INV-%d-%04d', $year, (int) (clone $counter)->value('last_number'));
    }
}
