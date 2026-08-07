<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChatMessageRequest extends FormRequest
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
        $userId = $this->user()->id;

        return [
            'message' => ['required', 'string', 'min:1', 'max:4000'],

            'conversation_id' => [
                'nullable',
                'integer',
                Rule::exists('assistant_conversations', 'id')->where('user_id', $userId),
            ],

            'workspace_id' => [
                'nullable',
                'integer',
                Rule::exists('workspace_users', 'workspace_id')->where('user_id', $userId),
            ],

            'page_context' => ['nullable', 'array'],
            'page_context.page' => ['nullable', 'string', 'max:120'],
            'page_context.route' => ['nullable', 'string', 'max:120'],
            'page_context.workspace_id' => [
                'nullable',
                'integer',
                Rule::exists('workspace_users', 'workspace_id')->where('user_id', $userId),
            ],

            'model' => ['nullable', 'string', Rule::in(config('assistant.allowed_models'))],
        ];
    }
}
