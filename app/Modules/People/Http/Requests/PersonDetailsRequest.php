<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\People\Data\StorePersonData;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation shared by adding and editing a person. Only these fields reach
 * the model; workspace, department and linked user are set by actions.
 */
abstract class PersonDetailsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'string', 'email', 'max:254'],
            'title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer', $this->linkableUser()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'department.max' => 'Keep the department name under 80 characters.',
        ];
    }

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    public function toDTO(): StorePersonData
    {
        $validated = $this->validated();

        return new StorePersonData(
            name: $validated['name'],
            email: $validated['email'] ?? null,
            title: $validated['title'] ?? null,
            department: $validated['department'] ?? null,
            user_id: isset($validated['user_id']) ? (int) $validated['user_id'] : null,
        );
    }

    /**
     * The person being edited, so their own link does not count as "taken".
     */
    protected function currentPerson(): ?Person
    {
        return null;
    }

    /**
     * Only members of this workspace who are not already linked to someone else.
     */
    private function linkableUser(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $workspace = $this->workspace();

            if (! $workspace->users()->whereKey((int) $value)->exists()) {
                $fail('Choose someone who is a member of this workspace.');

                return;
            }

            $takenBy = $workspace->people()
                ->where('user_id', (int) $value)
                ->when($this->currentPerson(), fn ($query, Person $person) => $query->whereKeyNot($person->getKey()))
                ->value('name');

            if ($takenBy !== null) {
                $fail("That user is already linked to {$takenBy}.");
            }
        };
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'email', 'title', 'department'] as $field) {
            if (is_string($this->input($field))) {
                $trimmed = trim($this->input($field));
                $this->merge([$field => $trimmed === '' ? null : $trimmed]);
            }
        }

        if ($this->input('user_id') === '') {
            $this->merge(['user_id' => null]);
        }
    }
}
