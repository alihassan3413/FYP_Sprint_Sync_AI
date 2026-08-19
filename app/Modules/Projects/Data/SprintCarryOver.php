<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

/**
 * What happens to work that is still open when a sprint is closed.
 */
enum SprintCarryOver: string
{
    case Backlog = 'backlog';
    case NextSprint = 'next_sprint';

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
            self::Backlog => 'Move unfinished work back to the backlog',
            self::NextSprint => 'Carry unfinished work into the next planned sprint',
        };
    }
}
