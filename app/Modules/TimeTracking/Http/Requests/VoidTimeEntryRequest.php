<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

final class VoidTimeEntryRequest extends TimeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('void', $this->route('timeEntry'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
