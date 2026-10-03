<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Models\Client;

final class RestoreClientAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Client $client, User $actor): Client
    {
        if (! $client->isArchived()) {
            return $client;
        }

        $client->forceFill(['archived_at' => null])->save();

        $this->auditLogger->handle(
            $client->workspace,
            null,
            $actor,
            AuditAction::CLIENT_RESTORED,
            "{$actor->name} restored the client \"{$client->name}\".",
            $client,
        );

        return $client;
    }
}
