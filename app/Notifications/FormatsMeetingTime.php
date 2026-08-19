<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\Time\UserTime;
use Illuminate\Support\Carbon;

trait FormatsMeetingTime
{
    private function localScheduledAt(object $notifiable, string $scheduledAtUtc): string
    {
        return UserTime::format(
            Carbon::parse($scheduledAtUtc, 'UTC'),
            $notifiable instanceof User ? $notifiable->timezone : null,
        );
    }
}
