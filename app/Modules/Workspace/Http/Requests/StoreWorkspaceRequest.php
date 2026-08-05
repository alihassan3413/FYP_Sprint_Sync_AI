<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Requests;

use App\Modules\Workspace\Data\WorkspaceData;
use App\Modules\Workspace\Rules\WorkspaceSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class StoreWorkspaceRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'slug' => ['required', 'string', 'max:60', 'unique:workspaces,slug', new WorkspaceSlug],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('slug') && $this->filled('name')) {
            $this->merge(['slug' => Str::slug((string) $this->input('name'))]);
        }
    }

    public function toDTO(): WorkspaceData
    {
        return WorkspaceData::from($this->validated());
    }
}
