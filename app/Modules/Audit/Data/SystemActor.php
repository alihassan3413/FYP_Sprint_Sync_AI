<?php

declare(strict_types=1);

namespace App\Modules\Audit\Data;

/**
 * SprintSync acting on its own (scheduled automation). Passed where a person
 * would be, so the record says "SprintSync" instead of borrowing a user id.
 */
final readonly class SystemActor
{
    public const NAME = 'SprintSync';

    public string $name;

    public function __construct()
    {
        $this->name = self::NAME;
    }
}
