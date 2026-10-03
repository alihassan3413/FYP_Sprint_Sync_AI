<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Models\Client;

/**
 * Archiving hides a client from everyday lists without losing anything, and is
 * undone with RestoreClientAction. Clients are never hard-deleted from the UI:
 * their invoices must keep pointing at them.
 */
final class ArchiveClientAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Client $client, User $actor): Client
    {
        if ($client->isArchived()) {
            return $client;
        }

        $client->forceFill(['archived_at' => now()])->save();

        $this->auditLogger->handle(
            $client->workspace,
            null,
            $actor,
            AuditAction::CLIENT_ARCHIVED,
            "{$actor->name} archived the client \"{$client->name}\".",
            $client,
        );

        return $client;
    }
}
