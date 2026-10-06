<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * The browser's filename and MIME type are never trusted: `image` and `mimes`
 * inspect the file's contents, and the dimensions rule makes PHP decode it, so
 * a renamed script or a polyglot file fails. SVG is excluded (it can carry
 * script) and the stored name is generated server-side.
 */
final class UpdateClientLogoRequest extends FormRequest
{
    public const MAX_KILOBYTES = 2048;

    public const MAX_DIMENSION = 4000;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->client()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                File::image()
                    ->max(self::MAX_KILOBYTES)
                    ->dimensions(Rule::dimensions()->maxWidth(self::MAX_DIMENSION)->maxHeight(self::MAX_DIMENSION)),
                'mimes:png,jpg,jpeg,webp',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.required' => 'Choose an image to upload.',
            'logo.image' => 'The logo must be a PNG, JPEG or WebP image.',
            'logo.mimes' => 'The logo must be a PNG, JPEG or WebP image.',
            'logo.max' => 'The logo must be smaller than 2 MB.',
            'logo.dimensions' => 'The logo must be at most '.self::MAX_DIMENSION.' pixels wide and tall.',
        ];
    }

    public function client(): Client
    {
        return $this->route('client');
    }

    public function logo(): UploadedFile
    {
        return $this->file('logo');
    }
}
