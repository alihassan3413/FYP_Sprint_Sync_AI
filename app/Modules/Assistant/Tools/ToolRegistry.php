<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;

class ToolRegistry
{
    /** @var array<string, AssistantTool> */
    private array $tools = [];

    public function register(AssistantTool $tool): void
    {
        $name = $tool->name();

        if (isset($this->tools[$name])) {
            throw new \LogicException(
                "Tool '{$name}' is already registered. Tool names must be unique."
            );
        }

        $this->tools[$name] = $tool;
    }

    public function get(string $name): ?AssistantTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return AssistantTool[]
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * Tools the given user is authorized to use.
     * This is the ONLY list ever sent to the LLM.
     *
     * @return AssistantTool[]
     */
    public function availableFor(User $user): array
    {
        return array_values(array_filter(
            $this->tools,
            fn (AssistantTool $tool) => $tool->authorize($user),
        ));
    }

    /**
     * OpenAI-style function schema. Gemini accepts the same shape.
     *
     * @return array<int, array{type: string, function: array}>
     */
    public function asOpenAiSchema(User $user): array
    {
        return array_map(
            fn (AssistantTool $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parameters(),
                ],
            ],
            $this->availableFor($user),
        );
    }
}
