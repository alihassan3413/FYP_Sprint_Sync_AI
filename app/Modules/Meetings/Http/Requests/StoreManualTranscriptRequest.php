<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;

final class StoreManualTranscriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageTranscript', $this->meeting()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:20', 'max:200000'],
        ];
    }

    public function meeting(): Meeting
    {
        return $this->route('meeting');
    }
}
