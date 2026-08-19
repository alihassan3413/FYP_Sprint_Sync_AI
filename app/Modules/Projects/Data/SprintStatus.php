<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

/**
 * Where a sprint sits in its lifecycle. This is an explicit state the team moves
 * through, not something inferred from the calendar: a sprint someone forgot to
 * start is not the same as a sprint that is running late.
 */
enum SprintStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Completed = 'completed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Active => 'Active',
            self::Completed => 'Completed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Planned => 'Being filled with work. Nothing is committed yet.',
            self::Active => 'Running. The team is working through the committed scope.',
            self::Completed => 'Closed. Its numbers are frozen and count towards velocity.',
        };
    }

    public function isPlanned(): bool
    {
        return $this === self::Planned;
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    /**
     * A completed sprint is a historical record, so it is never edited again.
     */
    public function isEditable(): bool
    {
        return $this !== self::Completed;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Planned => $next === self::Active,
            self::Active => $next === self::Completed,
            self::Completed => false,
        };
    }
}
