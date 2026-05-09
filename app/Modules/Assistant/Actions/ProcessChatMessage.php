<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Actions;

use App\Models\User;
use Generator;
use Illuminate\Support\Facades\Log;
use App\Modules\Assistant\Contracts\AiProvider;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Workspace\Models\Workspace;
use Throwable;

class ProcessChatMessage
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly ToolRegistry $registry,
        private readonly BuildContextPayload $contextBuilder,
        private readonly ExecuteToolCall $toolExecutor,
    ) {}

    /**
     * @param array{page?: string, route?: string} $pageContext
     * @return Generator<int, array{type: string, ...}>
     */
    public function handle(
        User $user,
        Conversation $conversation,
        string $userMessage,
        array $pageContext = [],
        string $model = 'gpt-4o-mini',
    ): Generator {
        $userMsg = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        // Tell the UI the message was saved (gives it a real ID to use).
        yield [
            'type' => 'user_message_saved',
            'id' => $userMsg->id,
        ];

        try {
            yield from $this->runLlmRound($user, $conversation, $pageContext, $model);
        } catch (Throwable $e) {
            Log::error('Assistant orchestration failed', [
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            yield [
                'type' => 'error',
                'message' => 'Something went wrong. Please try again.',
            ];
        }
    }

    /**
     * One round-trip with the LLM. After tool execution, we recursively
     * call this again to let the LLM compose a final reply using tool
     * results. Capped at 5 rounds to prevent infinite loops.
     */
    private function runLlmRound(
        User $user,
        Conversation $conversation,
        array $pageContext,
        string $model,
        int $depth = 0,
    ): Generator {
        if ($depth >= 5) {
            yield ['type' => 'error', 'message' => 'Conversation got too complex. Please start a new one.'];
            return;
        }

        $workspace = $conversation->workspace_id
            ? Workspace::find($conversation->workspace_id)
            : null;

        // Build the messages array sent to the LLM.
        $context = $this->contextBuilder->handle($user, $workspace, $pageContext);
        $messages = [['role' => 'system', 'content' => $context['system']]];

        // Last 30 messages — enough context, bounded token cost.
        // For longer conversations, look into summarization strategies.
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->slice(-30)
            ->map(fn (Message $m) => $m->toApiFormat())
            ->all();

        $messages = array_merge($messages, $history);

        // Tools available to THIS user (permission-filtered).
        $tools = $this->registry->asOpenAiSchema($user);

        // Accumulate the assistant's final message as we stream.
        $assistantText = '';
        $toolCalls = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];

        foreach ($this->provider->streamChat($messages, $tools, $model) as $event) {
            switch ($event['type']) {
                case 'text':
                    $assistantText .= $event['delta'];
                    yield ['type' => 'text', 'delta' => $event['delta']];
                    break;

                case 'tool_call':
                    $toolCalls[] = [
                        'id' => $event['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $event['name'],
                            'arguments' => json_encode($event['args']),
                        ],
                    ];
                    break;

                case 'usage':
                    $usage['input_tokens'] = $event['input_tokens'];
                    $usage['output_tokens'] = $event['output_tokens'];
                    break;

                case 'finish':
                    // Stream done. Now decide what to do based on whether
                    // there are tool calls to handle.
                    break;
            }
        }

        // Persist the assistant's message.
        $assistantMsg = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $assistantText !== '' ? $assistantText : null,
            'tool_calls' => ! empty($toolCalls) ? $toolCalls : null,
            'provider' => $this->provider->name(),
            'model' => $model,
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
        ]);

        $conversation->recordTokenUsage($usage['input_tokens'], $usage['output_tokens']);

        // No tools called → we're done.
        if (empty($toolCalls)) {
            yield ['type' => 'done', 'message_id' => $assistantMsg->id];
            return;
        }

        // Tools called → handle each one.
        foreach ($toolCalls as $toolCall) {
            yield from $this->handleToolCall($user, $conversation, $toolCall);
        }

        // Check whether any tools are pending confirmation. If so, STOP.
        // The user confirms via separate endpoint which restarts the loop.
        $hasPending = $conversation->messages()
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            yield ['type' => 'awaiting_confirmation'];
            return;
        }

        // All tools auto-executed → recurse so the LLM can use results
        // to compose its final reply.
        yield from $this->runLlmRound($user, $conversation, $pageContext, $model, $depth + 1);
    }

    private function handleToolCall(User $user, Conversation $conversation, array $toolCall): Generator
    {
        $name = $toolCall['function']['name'];
        $args = json_decode($toolCall['function']['arguments'], true) ?? [];
        $tool = $this->registry->get($name);

        // Defense in depth: tool might have been removed since the LLM was
        // told about it (very rare race). Treat as failure gracefully.
        if (! $tool || ! $tool->authorize($user)) {
            $this->recordToolResult($conversation, $toolCall['id'], $name, [
                'success' => false,
                'error' => 'Tool not available or unauthorized.',
            ], Message::STATUS_FAILED);

            yield [
                'type' => 'tool_failed',
                'tool_call_id' => $toolCall['id'],
                'name' => $name,
            ];
            return;
        }

        if ($tool->requiresConfirmation()) {
            // Save as pending, yield to UI, stop. User must confirm.
            $pendingMsg = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'],
                'tool_status' => Message::STATUS_PENDING,
                'metadata' => ['name' => $name, 'args' => $args],
            ]);

            yield [
                'type' => 'tool_pending',
                'message_id' => $pendingMsg->id,
                'tool_call_id' => $toolCall['id'],
                'name' => $name,
                'args' => $args,
                'description' => $tool->description(),
            ];
            return;
        }

        // Auto-executable (read-only). Run it, store the result.
        $result = $this->toolExecutor->handle($tool, $args, $user);

        $this->recordToolResult($conversation, $toolCall['id'], $name, $result, Message::STATUS_EXECUTED);

        yield [
            'type' => 'tool_executed',
            'tool_call_id' => $toolCall['id'],
            'name' => $name,
            'result' => $result,
        ];
    }

    private function recordToolResult(
        Conversation $conversation,
        string $toolCallId,
        string $name,
        array $result,
        string $status,
    ): void {
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'tool_status' => $status,
            'content' => json_encode($result),
            'metadata' => ['name' => $name],
        ]);
    }
}
