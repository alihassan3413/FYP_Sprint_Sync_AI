<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Models\InvoicingProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates or updates the workspace's invoicing details. Existing invoices keep
 * the details they were prepared with (their bill_from snapshot).
 */
final class SaveInvoicingProfileAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    /**
     * @param  array<string, string|null>  $details
     */
    public function handle(Workspace $workspace, array $details, User $actor): InvoicingProfile
    {
        $profile = InvoicingProfile::query()->where('workspace_id', $workspace->id)->first()
            ?? (new InvoicingProfile)->forceFill(['workspace_id' => $workspace->id]);
        $profile->fill($details);
        $changed = array_keys($profile->getDirty());

        try {
            $profile->save();
        } catch (UniqueConstraintViolationException) {
            /* A parallel first save created the row; apply these details to it. */
            $profile = InvoicingProfile::query()->where('workspace_id', $workspace->id)->sole();
            $profile->fill($details)->save();
        }

        if ($changed !== []) {
            $this->auditLogger->handle(
                $workspace,
                null,
                $actor,
                AuditAction::INVOICING_UPDATED,
                "{$actor->name} updated the workspace's invoicing details.",
                $profile,
                ['changed' => array_values(array_diff($changed, ['workspace_id']))],
            );
        }

        return $profile;
    }
}
