<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\StoreClientData;
use App\Modules\Billing\Models\Client;

final class UpdateClientAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Client $client, StoreClientData $data, User $actor): Client
    {
        $client->update($data->toAttributes());

        $changed = array_keys($client->getChanges());
        $changed = array_values(array_diff($changed, ['updated_at']));

        if ($changed !== []) {
            $this->auditLogger->handle(
                $client->workspace,
                null,
                $actor,
                AuditAction::CLIENT_UPDATED,
                "{$actor->name} updated the client \"{$client->name}\".",
                $client,
                ['changed' => $changed],
            );
        }

        return $client;
    }
}
