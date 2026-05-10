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
     * @param  array{page?: string, route?: string}  $pageContext
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

        // If the user sent a new message while one or more tools were
        // awaiting confirmation, treat those as superseded: the user is
        // either amending args, canceling, or moving on. Resolving them
        // now keeps the conversation history valid for the LLM (an
        // unresolved tool message has no content and OpenAI 400s on it).
        $supersededIdsByName = [];
        try {
            yield from $this->supersedePendingTools($conversation, $supersededIdsByName);
        } catch (Throwable $e) {
            report($e);
            yield $this->errorEvent($e);
            return;
        }

        try {
            yield from $this->runLlmRound(
                user: $user,
                conversation: $conversation,
                pageContext: $pageContext,
                model: $model,
                supersededIdsByName: $supersededIdsByName,
            );
        } catch (Throwable $e) {
            report($e);
            yield $this->errorEvent($e);
        }
    }

    /**
     * Find any pending tool messages and resolve them with a synthetic
     * tool result so the conversation history can be replayed safely to
     * the LLM. Returns a map of tool name => most recent superseded
     * message id, used later to tag re-emitted tool_pending events.
     *
     * @param  array<string, int>  $supersededIdsByName  passed by reference; populated here
     */
    private function supersedePendingTools(Conversation $conversation, array &$supersededIdsByName): Generator
    {
        $pending = $conversation->messages()
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_PENDING)
            ->get();

        foreach ($pending as $msg) {
            $name = $msg->metadata['name'] ?? null;
            $args = $msg->metadata['args'] ?? [];

            $msg->update([
                'tool_status' => Message::STATUS_SUPERSEDED,
                'content' => json_encode([
                    'superseded' => true,
                    'reason' => 'User sent a new message while this action was awaiting confirmation. Treat their latest message as an amendment, cancellation, or unrelated turn — and act accordingly.',
                ]),
            ]);

            if ($name !== null) {
                $supersededIdsByName[$name] = $msg->id;
            }

            yield [
                'type' => 'tool_superseded',
                'message_id' => $msg->id,
                'name' => $name,
                'args' => $args,
            ];
        }
    }

    /**
     * One round-trip with the LLM. After tool execution, we recursively
     * call this again to let the LLM compose a final reply using tool
     * results. Capped at 5 rounds to prevent infinite loops.
     *
     * @param  array<string, int>  $supersededIdsByName  tool name => message id of a tool just superseded
     */
    private function runLlmRound(
        User $user,
        Conversation $conversation,
        array $pageContext,
        string $model,
        int $depth = 0,
        array $supersededIdsByName = [],
    ): Generator {
        if ($depth >= 5) {
            yield ['type' => 'error', 'message' => 'Conversation got too complex. Please start a new one.'];
            return;
        }

        $workspace = $conversation->workspace_id
            ? Workspace::find($conversation->workspace_id)
            : null;

        // Pass any superseded actions into the system prompt so the LLM
        // knows the user may be amending or canceling them. Only relevant
        // on the first round; deeper rounds are tool-result follow-ups.
        $supersededActions = $depth === 0
            ? $this->buildSupersededActions($conversation, $supersededIdsByName)
            : [];

        // Build the messages array sent to the LLM.
        $context = $this->contextBuilder->handle($user, $workspace, $pageContext, $supersededActions);
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
            yield from $this->handleToolCall($user, $conversation, $toolCall, $supersededIdsByName);
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
        yield from $this->runLlmRound(
            user: $user,
            conversation: $conversation,
            pageContext: $pageContext,
            model: $model,
            depth: $depth + 1,
            supersededIdsByName: [],
        );
    }

    /**
     * @param  array<string, int>  $supersededIdsByName
     * @return array<int, array{name: string, args: array}>
     */
    private function buildSupersededActions(Conversation $conversation, array $supersededIdsByName): array
    {
        if (empty($supersededIdsByName)) {
            return [];
        }

        $messages = $conversation->messages()
            ->whereIn('id', array_values($supersededIdsByName))
            ->get()
            ->keyBy('id');

        $actions = [];
        foreach ($supersededIdsByName as $name => $id) {
            $msg = $messages->get($id);
            if (! $msg) {
                continue;
            }
            $actions[] = [
                'name' => $name,
                'args' => $msg->metadata['args'] ?? [],
            ];
        }

        return $actions;
    }

    /**
     * @param  array<string, int>  $supersededIdsByName
     */
    private function handleToolCall(
        User $user,
        Conversation $conversation,
        array $toolCall,
        array $supersededIdsByName = [],
    ): Generator {
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

            $event = [
                'type' => 'tool_pending',
                'message_id' => $pendingMsg->id,
                'tool_call_id' => $toolCall['id'],
                'name' => $name,
                'args' => $args,
                'description' => $tool->description(),
            ];

            // If this re-emits a tool the user was just amending, tell the
            // UI to update the existing card in place rather than render
            // a duplicate.
            if (isset($supersededIdsByName[$name])) {
                $event['replaces_message_id'] = $supersededIdsByName[$name];
            }

            yield $event;
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

    /**
     * Friendly error event. Real exception is reported via report() so
     * Sentry/logs catch it; the UI sees a short, calm bubble. Locally
     * we surface the raw message so dev iteration is fast.
     */
    private function errorEvent(Throwable $e): array
    {
        $message = 'Something went wrong while generating a response. Please try again.';

        if (app()->environment('local')) {
            $message .= ' ['.$e->getMessage().']';
        }

        return ['type' => 'error', 'message' => $message];
    }
}
