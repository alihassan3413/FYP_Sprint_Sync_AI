<?php

namespace App\Modules\Workspace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

final class AcceptWorkspaceInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /**
         * If user is already logged in, they don't need to submit name/password.
         * If guest user, Accept.vue must submit name/password.
         */
        if ($this->user()) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ];
    }
}
