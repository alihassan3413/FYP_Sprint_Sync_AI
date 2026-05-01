<?php

namespace App;

enum UserRole: string
{
    case OWNER = 'owner';
    case MEMBER = 'member';
    case ADMIN = 'admin';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function invitationRoles(): array
    {
        return [
            self::ADMIN->value,
            self::MEMBER->value,
        ];
    }
}
