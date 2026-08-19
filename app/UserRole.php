<?php

declare(strict_types=1);

namespace App;

enum UserRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MEMBER = 'member';
    case CLIENT = 'client';

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
        return [self::ADMIN->value, self::MEMBER->value, self::CLIENT->value];
    }

    public function rank(): int
    {
        return match ($this) {
            self::OWNER => 3,
            self::ADMIN => 2,
            self::MEMBER => 1,
            self::CLIENT => 0,
        };
    }

    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    public function isClient(): bool
    {
        return $this === self::CLIENT;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function description(): string
    {
        return match ($this) {
            self::OWNER => 'Full access. Cannot be removed or reassigned.',
            self::ADMIN => 'Manage members, roles, projects and workspace settings.',
            self::MEMBER => 'Access the projects they are assigned to.',
            self::CLIENT => 'External guest. Sees only the projects they are added to, and only what their client role allows.',
        };
    }
}
