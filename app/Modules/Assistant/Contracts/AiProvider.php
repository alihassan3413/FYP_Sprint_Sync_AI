<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Contracts;

use Generator;

/**
 * Abstraction over the LLM API. OpenAI, Gemini, Anthropic, etc. all
 * implement this so the rest of the app doesn't know which one is in use.
 *
 * Why this is critical in production:
 * - Provider outages: when OpenAI has an incident (it happens), you flip
 *   one config flag and traffic moves to Gemini. No code change.
 * - Cost optimization: route simple requests to cheaper providers, complex
 *   ones to more capable ones. Same interface, different implementation.
 * - A/B testing: ship a feature flag that routes 10% of users to a new
 *   provider to compare quality.
 */
interface AiProvider
{
    /**
     * Stream a chat completion as Server-Sent Events.
     *
     * Yields events of these shapes (always JSON-serializable arrays):
     *   ['type' => 'text', 'delta' => '...']           // streaming text chunk
     *   ['type' => 'tool_call', 'id' => '...', 'name' => '...', 'args' => [...]]
     *   ['type' => 'finish', 'reason' => 'stop'|'tool_calls'|'length']
     *   ['type' => 'usage', 'input_tokens' => N, 'output_tokens' => N]
     *
     * Why a normalized event shape: OpenAI streams differently from Gemini
     * (different field names, different streaming chunk semantics). The
     * provider implementation translates raw API events into this shape so
     * downstream code stays provider-agnostic.
     *
     * @param array<int, array{role: string, content?: string, tool_calls?: array, tool_call_id?: string}> $messages
     * @param array<int, array> $tools  JSON Schema function definitions
     * @return Generator<int, array{type: string, ...}>
     */
    public function streamChat(
        array $messages,
        array $tools,
        string $model,
        float $temperature = 0.7,
    ): Generator;

    /**
     * Identifier for logs and metrics. e.g. 'openai', 'gemini'.
     */
    public function name(): string;
}
