<?php

declare(strict_types=1);

namespace App\Notifications;

enum NotificationChannel: string
{
    case IN_APP = 'in_app';
    case EMAIL = 'email';

    public function label(): string
    {
        return match ($this) {
            self::IN_APP => 'In-app',
            self::EMAIL => 'Email',
        };
    }
}
