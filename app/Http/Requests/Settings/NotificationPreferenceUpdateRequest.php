<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Notifications\NotificationChannel;
use App\Notifications\NotificationType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class NotificationPreferenceUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.type' => ['required', 'string', 'in:'.implode(',', array_column(NotificationType::values(), 'value'))],
            'preferences.*.channel' => ['required', 'string', 'in:'.implode(',', array_map(fn ($channel) => $channel->value, NotificationChannel::cases()))],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->input('preferences', []) as $index => $preference) {
                    $type = NotificationType::tryFrom($preference['type'] ?? '');
                    $channel = NotificationChannel::tryFrom($preference['channel'] ?? '');

                    if ($type !== null && $channel !== null && ! $type->supportsChannel($channel)) {
                        $validator->errors()->add("preferences.{$index}.channel", 'This notification type does not support that channel.');
                    }
                }
            },
        ];
    }
}
