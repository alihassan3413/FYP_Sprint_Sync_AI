<?php

declare(strict_types=1);

namespace App\Modules\People\Data;

use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The SprintSync login a person is linked to. User ids are already used
 * throughout the app (member lists, assignees); only Finance and People
 * records are addressed by public ids.
 */
#[TypeScript]
final class LinkedUserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(id: $user->id, name: $user->name, email: $user->email);
    }
}
