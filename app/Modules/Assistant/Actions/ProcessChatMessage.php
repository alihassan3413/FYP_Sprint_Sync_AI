<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Actions;

use App\Models\User;
use App\Modules\Assistant\Contracts\AiProvider;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Support\ToolArgumentValidator;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\ToolResultEnvelope;
use App\Modules\Assistant\Tools\ToolRegistry;
use Generator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProcessChatMessage
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly ToolRegistry $registry,
        private readonly BuildContextPayload $contextBuilder,
        private readonly ExecuteToolCall $toolExecutor,
        private readonly ToolArgumentValidator $argumentValidator,
        private readonly ResolveConversationWorkspace $workspaceResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $pageContext
     * @return Generator<int, array<string, mixed>>
     */
    public function handle(
        User $user,
        Conversation $conversation,
        string $userMessage,
        array $pageContext = [],
        ?string $model = null,
    ): Generator {
        $model ??= (string) config('assistant.default_model');

        $userMsg = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        yield ['type' => 'user_message_saved', 'id' => $userMsg->id];

        $supersededIdsByName = [];

        try {
            yield from $this->supersedePendingTools($conversation, $supersededIdsByName);

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
     * A pending tool call is resolved with a synthetic result when the user sends a new
     * message, so the conversation history stays replayable for the provider.
     *
     * @param  array<string, int>  $supersededIdsByName
     * @return Generator<int, array<string, mixed>>
     */
    private function supersedePendingTools(Conversation $conversation, array &$supersededIdsByName): Generator
    {
        $pending = $conversation->messages()
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_PENDING)
            ->get();

        foreach ($pending as $message) {
            $name = $message->metadata['name'] ?? null;

            $message->update([
                'tool_status' => Message::STATUS_SUPERSEDED,
                'content' => json_encode([
                    'superseded' => true,
                    'reason' => 'The user sent a new message while this action was awaiting confirmation. '
                        .'Treat their latest message as an amendment, a cancellation, or an unrelated turn.',
                ]),
            ]);

            if ($name !== null) {
                $supersededIdsByName[$name] = $message->id;
            }

            yield [
                'type' => 'tool_superseded',
                'message_id' => $message->id,
                'name' => $name,
                'args' => $message->metadata['args'] ?? [],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $pageContext
     * @param  array<string, int>  $supersededIdsByName
     * @return Generator<int, array<string, mixed>>
     */
    private function runLlmRound(
        User $user,
        Conversation $conversation,
        array $pageContext,
        string $model,
        int $depth = 0,
        array $supersededIdsByName = [],
    ): Generator {
        if ($depth >= (int) config('assistant.max_tool_rounds')) {
            yield ['type' => 'error', 'message' => 'This conversation got too complex. Please start a new one.'];

            return;
        }

        $toolContext = $this->workspaceResolver->contextFor($conversation, $user);

        $context = $this->contextBuilder->handle(
            $user,
            $toolContext->workspace,
            $pageContext,
            $depth === 0 ? $this->buildSupersededActions($conversation, $supersededIdsByName) : [],
        );

        $messages = array_merge(
            [['role' => 'system', 'content' => $context['system']]],
            $this->history($conversation),
        );

        $assistantText = '';
        $toolCalls = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];

        foreach ($this->provider->streamChat($messages, $this->registry->asOpenAiSchema($toolContext), $model) as $event) {
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
                    $usage = [
                        'input_tokens' => $event['input_tokens'],
                        'output_tokens' => $event['output_tokens'],
                    ];
                    break;
            }
        }

        $assistantMsg = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $assistantText !== '' ? $assistantText : null,
            'tool_calls' => $toolCalls === [] ? null : $toolCalls,
            'provider' => $this->provider->name(),
            'model' => $model,
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
        ]);

        $conversation->recordTokenUsage($usage['input_tokens'], $usage['output_tokens']);

        if ($toolCalls === []) {
            yield ['type' => 'done', 'message_id' => $assistantMsg->id];

            return;
        }

        foreach ($toolCalls as $toolCall) {
            yield from $this->handleToolCall($conversation, $toolContext, $toolCall, $supersededIdsByName);
        }

        $awaitingConfirmation = $conversation->messages()
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_PENDING)
            ->exists();

        if ($awaitingConfirmation) {
            yield ['type' => 'awaiting_confirmation'];

            return;
        }

        yield from $this->runLlmRound(
            user: $user,
            conversation: $conversation,
            pageContext: $pageContext,
            model: $model,
            depth: $depth + 1,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function history(Conversation $conversation): array
    {
        return $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->slice(-(int) config('assistant.history_depth'))
            ->map(fn (Message $message) => $message->toApiFormat())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $supersededIdsByName
     * @return array<int, array{name: string, args: array<string, mixed>}>
     */
    private function buildSupersededActions(Conversation $conversation, array $supersededIdsByName): array
    {
        if ($supersededIdsByName === []) {
            return [];
        }

        $messages = $conversation->messages()
            ->whereIn('id', array_values($supersededIdsByName))
            ->get()
            ->keyBy('id');

        $actions = [];

        foreach ($supersededIdsByName as $name => $id) {
            $message = $messages->get($id);

            if ($message !== null) {
                $actions[] = ['name' => $name, 'args' => $message->metadata['args'] ?? []];
            }
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $toolCall
     * @param  array<string, int>  $supersededIdsByName
     * @return Generator<int, array<string, mixed>>
     */
    private function handleToolCall(
        Conversation $conversation,
        ToolContext $toolContext,
        array $toolCall,
        array $supersededIdsByName = [],
    ): Generator {
        $name = $toolCall['function']['name'];
        $args = json_decode($toolCall['function']['arguments'], true) ?? [];
        $tool = $this->registry->get($name);

        if ($tool === null) {
            $this->recordToolResult($conversation, $toolCall['id'], $name, [
                'success' => false,
                'error' => 'That action is not available to you.',
            ], Message::STATUS_FAILED);

            yield ['type' => 'tool_failed', 'tool_call_id' => $toolCall['id'], 'name' => $name];

            return;
        }

        try {
            $args = $this->argumentValidator->validate($tool, is_array($args) ? $args : []);
        } catch (ValidationException $e) {
            $this->recordToolResult($conversation, $toolCall['id'], $name, [
                'success' => false,
                'error_code' => 'invalid_arguments',
                'error' => 'The arguments were invalid: '.implode(' ', array_keys($e->errors())).'.',
            ], Message::STATUS_FAILED);

            yield ['type' => 'tool_failed', 'tool_call_id' => $toolCall['id'], 'name' => $name];

            return;
        }

        if (! $tool->authorize($toolContext)) {
            $this->recordToolResult($conversation, $toolCall['id'], $name, [
                'success' => false,
                'error' => 'That action is not available to you.',
            ], Message::STATUS_FAILED);

            yield ['type' => 'tool_failed', 'tool_call_id' => $toolCall['id'], 'name' => $name];

            return;
        }

        if ($tool->requiresConfirmation()) {
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

            if (isset($supersededIdsByName[$name])) {
                $event['replaces_message_id'] = $supersededIdsByName[$name];
            }

            yield $event;

            return;
        }

        $result = $this->toolExecutor->handle($tool, $args, $toolContext);

        $this->workspaceResolver->syncFromUser($conversation, $toolContext->user);

        $this->recordToolResult($conversation, $toolCall['id'], $name, $result, Message::STATUS_EXECUTED);

        yield [
            'type' => 'tool_executed',
            'tool_call_id' => $toolCall['id'],
            'name' => $name,
            'result' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
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
            'content' => ToolResultEnvelope::wrap($result),
            'metadata' => ['name' => $name],
        ]);
    }

    /**
     * @return array<string, string>
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
