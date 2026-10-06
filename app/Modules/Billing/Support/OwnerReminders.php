<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Workspace\Models\Workspace;
use App\Notifications\BillingAttentionNotification;
use Illuminate\Support\Facades\DB;

/**
 * Sends an owner notification at most once per key, however often the
 * scheduler runs or retries. The billing_reminders row (unique key) and the
 * database notification are written in one transaction: either both exist or
 * neither does, and a second insert of the same key is ignored.
 */
final class OwnerReminders
{
    /**
     * @return bool whether it was sent now (false: it had been sent before)
     */
    public function once(Workspace $workspace, string $key, string $type, string $title, string $message, string $url): bool
    {
        $owner = $workspace->owner;

        if ($owner === null) {
            return false;
        }

        return DB::transaction(function () use ($workspace, $owner, $key, $type, $title, $message, $url) {
            $inserted = DB::table('billing_reminders')->insertOrIgnore([
                'workspace_id' => $workspace->id,
                'reminder_key' => $key,
                'created_at' => now(),
            ]);

            if ($inserted !== 1) {
                return false;
            }

            $owner->notify(new BillingAttentionNotification($type, $title, $message, $url));

            return true;
        });
    }
}
