<?php

declare(strict_types=1);

namespace App\Modules\Audit\Data;

/**
 * Who an audit entry belongs to: a person (user_id is set) or SprintSync's
 * own automation (no user; shown as "SprintSync").
 */
enum ActorType: string
{
    case User = 'user';
    case System = 'system';
}
