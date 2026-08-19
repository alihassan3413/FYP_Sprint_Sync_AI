<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SpeakRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => [
                'required',
                'string',
                'min:1',
                'max:'.(int) config('assistant.speech.max_characters'),
            ],

            'voice' => [
                'nullable',
                'string',
                Rule::in(config('assistant.speech.allowed_voices')),
            ],
        ];
    }
}
