<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores a new logo and only then forgets the old one, so a failed upload never
 * leaves the client without the logo it had.
 */
final class UpdateClientLogoAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Client $client, UploadedFile $logo, User $actor): Client
    {
        $disk = Storage::disk(Client::LOGO_DISK);
        $previousPath = $client->logo_path;

        /* Generated name and an extension guessed from the file's contents: nothing from the browser reaches the path. */
        $path = $logo->storeAs(
            "workspaces/{$client->workspace_id}/clients/{$client->public_id}",
            Str::lower((string) Str::ulid()).'.'.$logo->extension(),
            Client::LOGO_DISK,
        );

        try {
            $client->forceFill(['logo_path' => $path])->save();
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        if ($previousPath !== null && $previousPath !== $path) {
            $disk->delete($previousPath);
        }

        $this->auditLogger->handle(
            $client->workspace,
            null,
            $actor,
            AuditAction::CLIENT_UPDATED,
            $previousPath === null
                ? "{$actor->name} added a logo for the client \"{$client->name}\"."
                : "{$actor->name} changed the logo for the client \"{$client->name}\".",
            $client,
            ['changed' => ['logo']],
        );

        return $client;
    }
}
