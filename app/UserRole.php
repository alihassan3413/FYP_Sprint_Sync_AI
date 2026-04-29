<?php

namespace App;

enum UserRole: string
{
    case OWNER = 'owner';
    case MEMBER = 'member';
    case ADMIN = 'admin';
}
