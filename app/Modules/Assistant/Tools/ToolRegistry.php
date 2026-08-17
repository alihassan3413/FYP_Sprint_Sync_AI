<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use LogicException;

final class ToolRegistry
{
    /** @var array<string, AssistantTool> */
    private array $tools = [];

    public function register(AssistantTool $tool): void
    {
        $name = $tool->name();

        if (isset($this->tools[$name])) {
            throw new LogicException("Tool '{$name}' is already registered. Tool names must be unique.");
        }

        $this->tools[$name] = $tool;
    }

    public function get(string $name): ?AssistantTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<int, AssistantTool>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * @return array<int, AssistantTool>
     */
    public function availableFor(ToolContext $context): array
    {
        return array_values(array_filter(
            $this->tools,
            fn (AssistantTool $tool) => $tool->authorize($context),
        ));
    }

    /**
     * @return array<int, array{type: string, function: array<string, mixed>}>
     */
    public function asOpenAiSchema(ToolContext $context): array
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
            $this->availableFor($context),
        );
    }
}
