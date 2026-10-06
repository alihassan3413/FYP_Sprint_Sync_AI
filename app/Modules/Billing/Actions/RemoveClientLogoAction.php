<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Models\Client;
use Illuminate\Support\Facades\Storage;

final class RemoveClientLogoAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Client $client, User $actor): Client
    {
        $previousPath = $client->logo_path;

        if ($previousPath === null) {
            return $client;
        }

        $client->forceFill(['logo_path' => null])->save();

        Storage::disk(Client::LOGO_DISK)->delete($previousPath);

        $this->auditLogger->handle(
            $client->workspace,
            null,
            $actor,
            AuditAction::CLIENT_UPDATED,
            "{$actor->name} removed the logo for the client \"{$client->name}\".",
            $client,
            ['changed' => ['logo']],
        );

        return $client;
    }
}
