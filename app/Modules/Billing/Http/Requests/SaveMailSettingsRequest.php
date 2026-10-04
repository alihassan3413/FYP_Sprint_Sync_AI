<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Data\MailEncryption;
use App\Modules\Billing\Models\WorkspaceMailSettings;
use App\Modules\Billing\Support\SmtpHostPolicy;
use App\Modules\Workspace\Models\Workspace;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The workspace's email server. The password is optional on update ("leave
 * blank to keep the current password") and is never echoed back (the
 * exception handler never flashes `password` to the session).
 */
final class SaveMailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', [WorkspaceMailSettings::class, $this->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hasPassword = $this->workspace()->mailSettings?->password !== null;

        return [
            'host' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && ($problem = SmtpHostPolicy::hostProblem($value))) {
                    $fail($problem);
                }
            }],
            'port' => ['required', 'integer', Rule::in(SmtpHostPolicy::allowedPorts())],
            'encryption' => ['required', Rule::enum(MailEncryption::class)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => [Rule::requiredIf(fn () => filled($this->input('username')) && ! $hasPassword), 'nullable', 'string', 'max:255'],
            'from_email' => ['required', 'string', 'email', 'max:254'],
            'from_name' => ['required', 'string', 'max:120'],
            'reply_to_email' => ['nullable', 'string', 'email', 'max:254'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'port.in' => 'Use a standard email port: '.implode(', ', SmtpHostPolicy::allowedPorts()).'.',
            'password.required' => 'Enter the password for this username.',
        ];
    }

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return collect($this->validated())->except('password')->all();
    }

    public function password(): ?string
    {
        $password = $this->validated('password');

        return filled($password) ? (string) $password : null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'host' => Str::lower(trim((string) $this->input('host'))),
            'username' => blank($this->input('username')) ? null : trim((string) $this->input('username')),
            'reply_to_email' => blank($this->input('reply_to_email')) ? null : trim((string) $this->input('reply_to_email')),
            'from_name' => Str::squish((string) $this->input('from_name')),
        ]);
    }
}
