<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Modules\Assistant\Contracts\AssistantTool;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ToolArgumentValidator
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(AssistantTool $tool, array $args): array
    {
        $schema = $tool->parameters();
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        $known = array_intersect_key($args, $properties);

        return Validator::make($known, $this->rulesFor($properties, $required))->validate();
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  array<int, string>  $required
     * @return array<string, array<int, string>>
     */
    private function rulesFor(array $properties, array $required): array
    {
        $rules = [];

        foreach ($properties as $name => $definition) {
            $fieldRules = [in_array($name, $required, true) ? 'required' : 'nullable'];

            $fieldRules[] = match ($definition['type'] ?? 'string') {
                'integer' => 'integer',
                'number' => 'numeric',
                'boolean' => 'boolean',
                'array' => 'array',
                'object' => 'array',
                default => 'string',
            };

            if (($definition['format'] ?? null) === 'email') {
                $fieldRules[] = 'email:rfc';
            }

            if (($definition['format'] ?? null) === 'date') {
                $fieldRules[] = 'date';
            }

            if (isset($definition['enum'])) {
                $fieldRules[] = 'in:'.implode(',', $definition['enum']);
            }

            if (isset($definition['minimum'])) {
                $fieldRules[] = 'min:'.$definition['minimum'];
            }

            if (isset($definition['maximum'])) {
                $fieldRules[] = 'max:'.$definition['maximum'];
            }

            if (isset($definition['minLength'])) {
                $fieldRules[] = 'min:'.$definition['minLength'];
            }

            if (isset($definition['maxLength'])) {
                $fieldRules[] = 'max:'.$definition['maxLength'];
            }

            $rules[$name] = $fieldRules;
        }

        return $rules;
    }
}
