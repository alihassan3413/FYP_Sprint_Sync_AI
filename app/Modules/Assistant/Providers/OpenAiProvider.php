<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Providers;

use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Modules\Assistant\Contracts\AiProvider;
use App\Modules\Assistant\Exceptions\AiProviderException;

/**
 * OpenAI provider with native streaming support.
 *
 * Why not use the openai-php/laravel package?
 * - It's excellent for non-streaming calls.
 * - For streaming with tool calls, raw HTTP gives us tighter control over
 *   chunk parsing, error handling, and timeout behavior. Tool call deltas
 *   in particular are tricky and the SDK abstractions can hide details we
 *   need for production debugging.
 * - This implementation is ~100 lines and shows you exactly what's
 *   happening — no magic.
 *
 * Production hardening included:
 * - Retry on transient network errors (handled by Http::retry).
 * - Timeout: connect=5s, total=120s. LLMs are slow but should not hang.
 * - Logs request_id from OpenAI on errors — you'll need it for support.
 * - Normalized event shape so downstream code is provider-agnostic.
 */
class OpenAiProvider implements AiProvider
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function streamChat(
        array $messages,
        array $tools,
        string $model,
        float $temperature = 0.7,
    ): Generator {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'stream' => true,
            // Stream usage stats in the final chunk. Without this, we can't
            // bill accurately. OpenAI changed this default in 2024.
            'stream_options' => ['include_usage' => true],
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
            // 'auto' lets the LLM choose to call a tool or respond with text.
            // 'required' forces a tool call (useful for routing scenarios).
            $payload['tool_choice'] = 'auto';
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ])
            ->timeout(120)         // total time
            ->connectTimeout(5)    // initial connection
            ->retry(2, 100, function ($exception, $request) {
                // Retry only transient errors. Don't retry on 4xx (our bug)
                // or 401 (bad key) — those won't fix themselves.
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            })
            // withOptions stream:true tells Guzzle to give us a stream
            // resource instead of buffering the whole response.
            ->withOptions(['stream' => true])
            ->post("{$this->baseUrl}/chat/completions", $payload);

        if (! $response->successful()) {
            $requestId = $response->header('x-request-id');
            $body = $response->body();
            Log::error('OpenAI stream request failed', [
                'status' => $response->status(),
                'request_id' => $requestId,
                'body' => substr($body, 0, 500),
            ]);
            throw new AiProviderException(
                "OpenAI request failed (status {$response->status()}, req_id {$requestId})"
            );
        }

        // Accumulator for tool call deltas. OpenAI streams tool calls in
        // pieces — we get partial JSON arguments across many chunks and
        // have to reassemble them. This is the biggest streaming gotcha.
        $toolCallAccumulator = [];

        $stream = $response->toPsrResponse()->getBody();

        // Buffer for incomplete SSE events split across reads.
        $buffer = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            // SSE events are separated by double newlines.
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                // Extract data lines from the event.
                foreach (explode("\n", $event) as $line) {
                    if (! str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $data = substr($line, 6);

                    // OpenAI signals end of stream with this sentinel.
                    if ($data === '[DONE]') {
                        return;
                    }

                    $parsed = json_decode($data, true);
                    if (! is_array($parsed)) {
                        continue;
                    }

                    yield from $this->parseChunk($parsed, $toolCallAccumulator);
                }
            }
        }
    }

    /**
     * Translate one OpenAI SSE chunk into our normalized event format.
     */
    private function parseChunk(array $chunk, array &$toolCallAccumulator): Generator
    {
        // Final chunk with usage data has no 'choices'. Yield usage and stop.
        if (isset($chunk['usage']) && ! isset($chunk['choices'])) {
            yield [
                'type' => 'usage',
                'input_tokens' => $chunk['usage']['prompt_tokens'] ?? 0,
                'output_tokens' => $chunk['usage']['completion_tokens'] ?? 0,
            ];
            return;
        }

        $choice = $chunk['choices'][0] ?? null;
        if (! $choice) {
            return;
        }

        $delta = $choice['delta'] ?? [];

        // Plain text content — most common case.
        if (isset($delta['content']) && $delta['content'] !== null) {
            yield ['type' => 'text', 'delta' => $delta['content']];
        }

        // Tool call deltas — accumulate, don't yield yet.
        if (isset($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $toolDelta) {
                $idx = $toolDelta['index'];
                $toolCallAccumulator[$idx] ??= [
                    'id' => null,
                    'name' => '',
                    'arguments' => '',
                ];

                if (isset($toolDelta['id'])) {
                    $toolCallAccumulator[$idx]['id'] = $toolDelta['id'];
                }
                if (isset($toolDelta['function']['name'])) {
                    $toolCallAccumulator[$idx]['name'] .= $toolDelta['function']['name'];
                }
                if (isset($toolDelta['function']['arguments'])) {
                    $toolCallAccumulator[$idx]['arguments'] .= $toolDelta['function']['arguments'];
                }
            }
        }

        // Stream finished — emit accumulated tool calls and finish event.
        if (isset($choice['finish_reason'])) {
            foreach ($toolCallAccumulator as $accumulated) {
                $args = json_decode($accumulated['arguments'], true) ?? [];
                yield [
                    'type' => 'tool_call',
                    'id' => $accumulated['id'],
                    'name' => $accumulated['name'],
                    'args' => $args,
                ];
            }

            yield [
                'type' => 'finish',
                'reason' => $choice['finish_reason'],
            ];
        }
    }
}
