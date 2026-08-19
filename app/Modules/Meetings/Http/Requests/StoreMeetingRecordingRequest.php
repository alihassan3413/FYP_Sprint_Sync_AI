<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;

final class StoreMeetingRecordingRequest extends FormRequest
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
            'recording' => [
                'required',
                'file',
                'max:'.(int) config('transcription.max_upload_kilobytes'),
                'mimetypes:'.implode(',', (array) config('transcription.allowed_mimetypes')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recording.mimetypes' => 'That file type is not a supported audio or video recording.',
        ];
    }

    public function meeting(): Meeting
    {
        return $this->route('meeting');
    }
}
