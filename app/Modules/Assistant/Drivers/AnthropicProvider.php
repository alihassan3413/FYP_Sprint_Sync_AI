<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Drivers;

use App\Modules\Assistant\Contracts\AiProvider;
use App\Modules\Assistant\Exceptions\AiProviderException;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Tools\ToolRegistry;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;

/**
 * Anthropic (Claude) provider with native streaming support.
 *
 * The rest of the Assistant module speaks OpenAI's message and tool shapes,
 * because that is what {@see Message::toApiFormat()}
 * and {@see ToolRegistry::asOpenAiSchema()} produce.
 * This driver translates in both directions so nothing upstream has to change:
 *
 *   OpenAI shape                     Anthropic shape
 *   ------------                     ---------------
 *   role: system message         ->  top-level "system" parameter
 *   assistant.tool_calls[]       ->  content blocks of type "tool_use"
 *   role: tool + tool_call_id    ->  user message with a "tool_result" block
 *   tools[].function.parameters  ->  tools[].input_schema
 *
 * Sampling parameters are deliberately not sent. Claude Sonnet 5 rejects a
 * non-default temperature/top_p/top_k with a 400, so the interface's
 * $temperature argument is accepted and ignored — response shape is steered
 * through the system prompt instead.
 */
class AnthropicProvider implements AiProvider
{
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';

    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $maxTokens = 4096,
        private readonly string $effort = 'medium',
        private readonly bool $thinking = false,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function streamChat(
        array $messages,
        array $tools,
        string $model,
        float $temperature = 0.7,
    ): Generator {
        [$system, $anthropicMessages] = $this->translateMessages($messages);

        $payload = [
            'model' => $model,
            'max_tokens' => $this->maxTokens,
            'messages' => $anthropicMessages,
            'stream' => true,
            // Effort is the intelligence/latency dial on Claude 4.6+ models.
            // It replaces the token-budget knobs older models used.
            'output_config' => ['effort' => $this->effort],
            // Thinking is off by default here. History is rebuilt from the
            // database on every round and does not carry thinking blocks, so
            // replaying a thinking turn that contains a tool_use would fail
            // validation. Turning it on requires persisting those blocks too.
            'thinking' => ['type' => $this->thinking ? 'adaptive' : 'disabled'],
        ];

        if ($system !== null) {
            $payload['system'] = $system;
        }

        if ($tools !== []) {
            $payload['tools'] = $this->translateTools($tools);
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ])
            ->timeout(120)
            ->connectTimeout(5)
            // throw: false keeps a non-2xx response as a value so the
            // request-id below makes it into the log and the exception.
            ->retry(2, 100, fn ($exception) => $exception instanceof ConnectionException, throw: false)
            ->withOptions(['stream' => true])
            ->post("{$this->baseUrl}/messages", $payload);

        if (! $response->successful()) {
            $requestId = $response->header('request-id');

            Log::error('Anthropic stream request failed', [
                'status' => $response->status(),
                'request_id' => $requestId,
                'body' => substr($response->body(), 0, 500),
            ]);

            throw new AiProviderException(
                "Anthropic request failed (status {$response->status()}, req_id {$requestId})"
            );
        }

        yield from $this->consumeStream($response->toPsrResponse()->getBody());
    }

    /**
     * @param  StreamInterface  $stream
     * @return Generator<int, array<string, mixed>>
     */
    private function consumeStream($stream): Generator
    {
        // Claude streams tool arguments as partial JSON across many deltas,
        // keyed by content block index. Reassemble before yielding.
        $toolBlocks = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $stopReason = null;
        $buffer = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(256);

            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            $buffer = str_replace("\r\n", "\n", $buffer);

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $rawEvent = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                foreach (explode("\n", $rawEvent) as $line) {
                    if (! str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $parsed = json_decode(substr($line, 6), true);

                    if (! is_array($parsed)) {
                        continue;
                    }

                    yield from $this->parseEvent($parsed, $toolBlocks, $usage, $stopReason);
                }
            }
        }

        foreach ($toolBlocks as $block) {
            $args = json_decode($block['json'] === '' ? '{}' : $block['json'], true);

            yield [
                'type' => 'tool_call',
                'id' => $block['id'],
                'name' => $block['name'],
                'args' => is_array($args) ? $args : [],
            ];
        }

        yield [
            'type' => 'usage',
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
        ];

        yield [
            'type' => 'finish',
            'reason' => $this->normalizeStopReason($stopReason),
        ];
    }

    /**
     * Translate one Anthropic SSE event into our normalized event format.
     *
     * @param  array<string, mixed>  $event
     * @param  array<int, array{id: string, name: string, json: string}>  $toolBlocks
     * @param  array{input_tokens: int, output_tokens: int}  $usage
     * @return Generator<int, array<string, mixed>>
     */
    private function parseEvent(array $event, array &$toolBlocks, array &$usage, ?string &$stopReason): Generator
    {
        switch ($event['type'] ?? null) {
            case 'message_start':
                $usage['input_tokens'] = (int) ($event['message']['usage']['input_tokens'] ?? 0);
                break;

            case 'content_block_start':
                $block = $event['content_block'] ?? [];

                if (($block['type'] ?? null) === 'tool_use') {
                    $toolBlocks[(int) $event['index']] = [
                        'id' => (string) ($block['id'] ?? ''),
                        'name' => (string) ($block['name'] ?? ''),
                        'json' => '',
                    ];
                }
                break;

            case 'content_block_delta':
                $delta = $event['delta'] ?? [];

                if (($delta['type'] ?? null) === 'text_delta') {
                    yield ['type' => 'text', 'delta' => (string) $delta['text']];
                }

                if (($delta['type'] ?? null) === 'input_json_delta') {
                    $index = (int) $event['index'];

                    if (isset($toolBlocks[$index])) {
                        $toolBlocks[$index]['json'] .= (string) ($delta['partial_json'] ?? '');
                    }
                }
                break;

            case 'message_delta':
                $stopReason = $event['delta']['stop_reason'] ?? $stopReason;
                $usage['output_tokens'] = (int) ($event['usage']['output_tokens'] ?? $usage['output_tokens']);
                break;

            case 'error':
                throw new AiProviderException(
                    'Anthropic stream error: '.($event['error']['message'] ?? 'unknown')
                );
        }
    }

    /**
     * Claude's stop reasons mapped onto the vocabulary {@see AiProvider} documents.
     */
    private function normalizeStopReason(?string $stopReason): string
    {
        return match ($stopReason) {
            'tool_use' => 'tool_calls',
            'max_tokens' => 'length',
            default => 'stop',
        };
    }

    /**
     * Split the OpenAI-shaped history into Claude's separate system parameter
     * and message list.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{0: string|null, 1: array<int, array<string, mixed>>}
     */
    private function translateMessages(array $messages): array
    {
        $system = null;
        $translated = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? '';

            if ($role === 'system') {
                $system = (string) ($message['content'] ?? '');

                continue;
            }

            $converted = match ($role) {
                'assistant' => $this->translateAssistantMessage($message),
                'tool' => $this->translateToolMessage($message),
                default => $this->translateUserMessage($message),
            };

            if ($converted === null) {
                continue;
            }

            $translated[] = $converted;
        }

        return [$system, $this->mergeConsecutiveRoles($translated)];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function translateUserMessage(array $message): ?array
    {
        $content = trim((string) ($message['content'] ?? ''));

        if ($content === '') {
            return null;
        }

        return ['role' => 'user', 'content' => [['type' => 'text', 'text' => $content]]];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function translateAssistantMessage(array $message): ?array
    {
        $blocks = [];
        $text = trim((string) ($message['content'] ?? ''));

        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }

        foreach ($message['tool_calls'] ?? [] as $toolCall) {
            $input = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);

            $blocks[] = [
                'type' => 'tool_use',
                'id' => (string) ($toolCall['id'] ?? ''),
                'name' => (string) ($toolCall['function']['name'] ?? ''),
                // An argumentless call decodes to [], which would encode back
                // as a JSON array. Claude requires an object here.
                'input' => $this->asJsonObject(is_array($input) ? $input : []),
            ];
        }

        // Claude rejects an assistant turn with no content blocks.
        return $blocks === [] ? null : ['role' => 'assistant', 'content' => $blocks];
    }

    /**
     * Tool results are user-role content blocks in Claude's format, not their
     * own role.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function translateToolMessage(array $message): ?array
    {
        $toolCallId = (string) ($message['tool_call_id'] ?? '');

        if ($toolCallId === '') {
            return null;
        }

        $content = (string) ($message['content'] ?? '');

        return [
            'role' => 'user',
            'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => $toolCallId,
                // A pending confirmation has no result recorded yet. Claude
                // requires every tool_use to be answered, so send a placeholder
                // rather than leaving the block dangling.
                'content' => $content !== '' ? $content : '{"status":"awaiting user confirmation"}',
            ]],
        ];
    }

    /**
     * Claude expects every tool_result for one assistant turn in a single user
     * message. Parallel tool calls arrive here as separate messages, so fold
     * neighbouring same-role entries together.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function mergeConsecutiveRoles(array $messages): array
    {
        $merged = [];

        foreach ($messages as $message) {
            $last = array_key_last($merged);

            if ($last !== null && $merged[$last]['role'] === $message['role']) {
                $merged[$last]['content'] = array_merge($merged[$last]['content'], $message['content']);

                continue;
            }

            $merged[] = $message;
        }

        return $merged;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function translateTools(array $tools): array
    {
        return array_values(array_map(
            fn (array $tool) => [
                'name' => $tool['function']['name'],
                'description' => $tool['function']['description'],
                'input_schema' => $this->normalizeSchema($tool['function']['parameters']),
            ],
            $tools,
        ));
    }

    /**
     * A tool that takes no arguments carries an empty `properties` array, which
     * PHP encodes as `[]`. Claude requires an object there and rejects the whole
     * request with a 400 — OpenAI accepts either, so this only bites here.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema): array
    {
        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = new \stdClass;
        }

        return $schema;
    }

    /**
     * PHP cannot tell an empty list from an empty map, and json_encode resolves
     * the ambiguity as `[]`. Claude rejects an array where it expects an object.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|\stdClass
     */
    private function asJsonObject(array $value): array|\stdClass
    {
        return $value === [] ? new \stdClass : $value;
    }
}
