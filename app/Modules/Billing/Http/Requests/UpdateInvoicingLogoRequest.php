<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Models\InvoicingProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * Same rules as client logos: PNG, JPEG or WebP only (no SVG, GIF or PDF),
 * checked by content and decoded for its dimensions. The file is then
 * re-encoded as PNG and stored under its content hash (InvoiceLogoStore).
 */
final class UpdateInvoicingLogoRequest extends FormRequest
{
    public const MAX_KILOBYTES = 2048;

    public const MAX_DIMENSION = 4000;

    public function authorize(): bool
    {
        return $this->user()?->can('manage', [InvoicingProfile::class, $this->workspace()]) ?? false;
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

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }
}
