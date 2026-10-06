<?php

declare(strict_types=1);

namespace App\Modules\Desktop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DesktopSignInRequest extends FormRequest
{
    public const PLATFORMS = ['macos', 'windows', 'linux'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:100'],
            'platform' => ['required', Rule::in(self::PLATFORMS)],
        ];
    }
}
