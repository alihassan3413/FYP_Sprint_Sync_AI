<?php

declare(strict_types=1);

namespace App\Modules\Attachments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAttachmentRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', (array) config('attachments.allowed_extensions')),
                'max:'.(int) config('attachments.max_kilobytes'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $megabytes = round(((int) config('attachments.max_kilobytes')) / 1024);

        return [
            'file.mimes' => 'That file type is not supported.',
            'file.max' => "Files need to be under {$megabytes}MB.",
        ];
    }
}
