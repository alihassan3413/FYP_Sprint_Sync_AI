<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Models\WorkspaceMailSettings;
use App\Modules\Workspace\Models\Workspace;

/**
 * Saves the workspace's email server. A blank password keeps the stored one.
 * Changing anything about the connection clears the "tested" mark, since the
 * old test no longer proves the new settings work. The audit entry lists
 * which fields changed, never their values.
 */
final class SaveMailSettingsAction
{
    private const CONNECTION_FIELDS = ['host', 'port', 'encryption', 'username', 'password', 'from_email'];

    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function handle(Workspace $workspace, array $details, ?string $password, User $actor): WorkspaceMailSettings
    {
        $settings = WorkspaceMailSettings::query()->where('workspace_id', $workspace->id)->first()
            ?? (new WorkspaceMailSettings)->forceFill(['workspace_id' => $workspace->id]);

        $settings->fill($details);

        if ($password !== null) {
            $settings->password = $password;
        }

        $changed = array_values(array_diff(array_keys($settings->getDirty()), ['workspace_id']));

        if (array_intersect($changed, self::CONNECTION_FIELDS) !== []) {
            $settings->verified_at = null;
        }

        $settings->save();

        if ($changed !== []) {
            $this->auditLogger->handle(
                $workspace,
                null,
                $actor,
                AuditAction::MAIL_SETTINGS_SAVED,
                "{$actor->name} saved the email delivery settings.",
                $settings,
                ['changed' => $changed],
            );
        }

        return $settings;
    }
}
