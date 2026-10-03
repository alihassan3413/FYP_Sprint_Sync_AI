<?php

declare(strict_types=1);

namespace App\Modules\People\Data;

use App\Modules\People\Models\Person;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the browser may know about a person. The database id is absent: a
 * person is addressed by public_id.
 */
#[TypeScript]
final class PersonData extends Data
{
    public function __construct(
        public string $public_id,
        public string $name,
        public ?string $email,
        public ?string $title,
        public ?DepartmentData $department,
        public ?LinkedUserData $linked_user,
        public string $created_at,
    ) {}

    public static function fromModel(Person $person): self
    {
        return new self(
            public_id: $person->public_id,
            name: $person->name,
            email: $person->email,
            title: $person->title,
            department: $person->department === null ? null : DepartmentData::fromModel($person->department),
            linked_user: $person->user === null ? null : LinkedUserData::fromModel($person->user),
            created_at: $person->created_at->toIso8601String(),
        );
    }
}
