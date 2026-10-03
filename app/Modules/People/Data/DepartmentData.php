<?php

declare(strict_types=1);

namespace App\Modules\People\Data;

use App\Modules\People\Models\Department;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DepartmentData extends Data
{
    public function __construct(
        public string $public_id,
        public string $name,
    ) {}

    public static function fromModel(Department $department): self
    {
        return new self(public_id: $department->public_id, name: $department->name);
    }
}
