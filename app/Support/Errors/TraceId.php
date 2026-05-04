<?php

declare(strict_types=1);

namespace App\Support\Errors;

use Illuminate\Support\Str;

final class TraceId
{
    private ?string $id = null;

    public function get(): string
    {
        return $this->id ??= (string) Str::ulid();
    }

    public function set(string $id): void
    {
        $this->id = $id;
    }
}
