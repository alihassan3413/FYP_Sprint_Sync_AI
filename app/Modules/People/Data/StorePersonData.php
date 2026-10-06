<?php

declare(strict_types=1);

namespace App\Modules\People\Data;

/**
 * The editable details of a person, for both creating and updating.
 * department is a name: an unknown one is created on the fly.
 */
final readonly class StorePersonData
{
    public function __construct(
        public string $name,
        public ?string $email = null,
        public ?string $title = null,
        public ?string $department = null,
        public ?int $user_id = null,
    ) {}

    /**
     * @return array{name: string, email: string|null, title: string|null}
     */
    public function details(): array
    {
        return ['name' => $this->name, 'email' => $this->email, 'title' => $this->title];
    }
}
