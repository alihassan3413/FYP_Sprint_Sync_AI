<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Requests;

use App\Support\Time\UserTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class AcceptWorkspaceInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->user() !== null) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'timezone' => UserTime::rules(),
        ];
    }
}
