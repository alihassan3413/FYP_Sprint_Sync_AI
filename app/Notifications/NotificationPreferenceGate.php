<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Collection;

final class NotificationPreferenceGate
{
    /**
     * @param  Collection<int, User>  $recipients
     * @return Collection<int, User>
     */
    public function filter(Collection $recipients, NotificationType $type, NotificationChannel $channel): Collection
    {
        if ($recipients->isEmpty()) {
            return $recipients;
        }

        $disabledUserIds = NotificationPreference::query()
            ->whereIn('user_id', $recipients->pluck('id'))
            ->where('type', $type->value)
            ->where('channel', $channel->value)
            ->where('enabled', false)
            ->pluck('user_id');

        if ($disabledUserIds->isEmpty()) {
            return $recipients;
        }

        return $recipients->reject(fn (User $recipient) => $disabledUserIds->contains($recipient->id))->values();
    }
}
