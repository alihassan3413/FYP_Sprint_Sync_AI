<?php

declare(strict_types=1);

namespace App;

enum ProjectRole: string
{
    case MANAGER = 'manager';
    case MEMBER = 'member';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function rank(): int
    {
        return match ($this) {
            self::MANAGER => 2,
            self::MEMBER => 1,
        };
    }

    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
