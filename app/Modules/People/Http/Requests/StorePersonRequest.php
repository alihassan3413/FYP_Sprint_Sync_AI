<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\People\Models\Person;

final class StorePersonRequest extends PersonDetailsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Person::class, $this->workspace()]) ?? false;
    }
}
