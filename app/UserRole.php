<?php

declare(strict_types=1);

namespace App;

enum UserRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MEMBER = 'member';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public static function invitationRoles(): array
    {
        return [self::ADMIN->value, self::MEMBER->value];
    }

    public function rank(): int
    {
        return match ($this) {
            self::OWNER => 3,
            self::ADMIN => 2,
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
