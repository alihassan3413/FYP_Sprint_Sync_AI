<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Models\InvoicingProfile;
use App\Modules\Billing\Support\InvoiceLogoStore;
use App\Modules\Workspace\Models\Workspace;

/**
 * Sets (or with null, removes) the workspace's invoice logo. The previous
 * file is only deleted when no invoice snapshot still uses it, so invoices
 * already prepared keep their logo.
 */
final class UpdateInvoicingLogoAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLogger,
        private readonly InvoiceLogoStore $logos,
    ) {}

    public function handle(InvoicingProfile $profile, ?string $uploadedBytes, User $actor): InvoicingProfile
    {
        $previous = $profile->logo_path;
        $path = $uploadedBytes === null ? null : $this->logos->store($profile->workspace_id, $uploadedBytes);

        if ($path === $previous) {
            return $profile;
        }

        $profile->forceFill(['logo_path' => $path])->save();
        $this->logos->deleteIfUnused($profile->workspace_id, $previous);

        /** @var Workspace $workspace */
        $workspace = $profile->workspace;

        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::INVOICING_UPDATED,
            $path === null ? "{$actor->name} removed the invoice logo." : "{$actor->name} changed the invoice logo.",
            $profile,
            ['changed' => ['logo']],
        );

        return $profile;
    }
}
