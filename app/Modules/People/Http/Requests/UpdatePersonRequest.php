<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\People\Models\Person;

final class UpdatePersonRequest extends PersonDetailsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->person()) ?? false;
    }

    public function person(): Person
    {
        return $this->route('person');
    }

    protected function currentPerson(): ?Person
    {
        return $this->person();
    }
}
