<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Requests;

use App\Modules\Assistant\Models\Message;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConfirmActionRequest extends FormRequest
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
            'message_id' => ['required', 'integer'],
            'action' => ['required', Rule::in(['confirm', 'reject'])],
        ];
    }

    public function pendingMessage(): Message
    {
        return Message::query()
            ->where('id', $this->integer('message_id'))
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_PENDING)
            ->whereHas('conversation', fn ($query) => $query->where('user_id', $this->user()->id))
            ->firstOrFail();
    }

    public function isConfirmation(): bool
    {
        return $this->input('action') === 'confirm';
    }
}
