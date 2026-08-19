<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Sprint;

final class UpdateSprintRequest extends StoreSprintRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->sprint()) ?? false;
    }

    public function sprint(): Sprint
    {
        return $this->route('sprint');
    }

    protected function overlapQuery()
    {
        return parent::overlapQuery()->whereKeyNot($this->sprint()->id);
    }
}
