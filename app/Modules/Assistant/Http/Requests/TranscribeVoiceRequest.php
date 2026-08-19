<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TranscribeVoiceRequest extends FormRequest
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
            'audio' => [
                'required',
                'file',
                'max:'.(int) config('assistant.voice.max_upload_kilobytes'),
                'mimetypes:'.implode(',', (array) config('assistant.voice.allowed_mimetypes')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'audio.mimetypes' => 'That recording format is not supported.',
            'audio.max' => 'That recording is too long. Please keep it shorter.',
        ];
    }
}
