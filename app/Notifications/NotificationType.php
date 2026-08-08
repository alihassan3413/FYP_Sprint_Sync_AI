<?php

declare(strict_types=1);

namespace App\Notifications;

enum NotificationType: string
{
    case MEETING_SCHEDULED = 'meeting_scheduled';
    case MEETING_UPDATED = 'meeting_updated';
    case MEETING_CANCELLED = 'meeting_cancelled';
    case TASK_ASSIGNED = 'task_assigned';
    case TASK_MOVED = 'task_moved';
    case TASK_COMMENT = 'task_comment';

    /**
     * @return array<int, self>
     */
    public static function values(): array
    {
        return self::cases();
    }

    public function group(): string
    {
        return match ($this) {
            self::MEETING_SCHEDULED, self::MEETING_UPDATED, self::MEETING_CANCELLED => 'Meetings',
            self::TASK_ASSIGNED, self::TASK_MOVED, self::TASK_COMMENT => 'Tasks',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MEETING_SCHEDULED => 'Scheduled meeting',
            self::MEETING_UPDATED => 'Meeting updated',
            self::MEETING_CANCELLED => 'Meeting cancelled',
            self::TASK_ASSIGNED => 'Task assigned',
            self::TASK_MOVED => 'Task moved',
            self::TASK_COMMENT => 'Task comment',
        };
    }

    /**
     * @return array<int, NotificationChannel>
     */
    public function channels(): array
    {
        return match ($this) {
            self::MEETING_SCHEDULED, self::MEETING_UPDATED, self::MEETING_CANCELLED => [
                NotificationChannel::IN_APP,
                NotificationChannel::EMAIL,
            ],
            self::TASK_ASSIGNED, self::TASK_MOVED, self::TASK_COMMENT => [
                NotificationChannel::IN_APP,
            ],
        };
    }

    public function supportsChannel(NotificationChannel $channel): bool
    {
        return in_array($channel, $this->channels(), true);
    }
}
