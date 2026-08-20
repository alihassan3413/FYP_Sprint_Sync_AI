<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CommandCatalogRequest extends FormRequest
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
        ];
    }
}
