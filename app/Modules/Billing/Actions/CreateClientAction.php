<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\StoreClientData;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Models\Workspace;

final class CreateClientAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Workspace $workspace, StoreClientData $data, User $actor): Client
    {
        /** @var Client $client */
        $client = $workspace->clients()->create($data->toAttributes());

        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::CLIENT_CREATED,
            "{$actor->name} added the client \"{$client->name}\".",
            $client,
            ['currency' => $client->currency->value],
        );

        return $client;
    }
}
