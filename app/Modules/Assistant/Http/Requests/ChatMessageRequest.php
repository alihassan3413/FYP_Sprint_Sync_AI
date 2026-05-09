<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:4000'],


            'conversation_id' => [
                'nullable',
                'integer',
                'exists:assistant_conversations,id',
            ],

            'workspace_id' => [
                'nullable',
                'integer',
                'exists:workspaces,id',
            ],

            'page_context' => ['nullable', 'array'],
            'page_context.page' => ['nullable', 'string', 'max:120'],
            'page_context.route' => ['nullable', 'string', 'max:120'],

            'model' => ['nullable', 'string', 'in:gpt-4o,gpt-4o-mini,gemini-2.0-flash'],
        ];
    }
}
