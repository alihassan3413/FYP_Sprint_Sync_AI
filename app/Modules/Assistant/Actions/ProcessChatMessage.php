<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Actions;

use App\Models\User;
use App\Modules\Assistant\Contracts\AiProvider;
use App\Modules\Assistant\Contracts\DefersConfirmation;
use App\Modules\Assistant\Contracts\ProvidesConfirmationDetails;
use App\Modules\Assistant\Exceptions\AiProviderException;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Support\ToolArgumentValidator;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\ToolFailure;
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
        bool $synthetic = false,
    ): Generator {
        $model ??= (string) config('assistant.default_model');

        $userMsg = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
            /*
             * Synthetic turns are the system talking to the model on the user's
             * behalf after a confirmation. They must not reset the turn boundary,
             * or the duplicate-action guard below loses its reference point.
             */
            'metadata' => $synthetic ? ['synthetic' => true] : null,
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

        $skipped = 0;

        foreach ($toolCalls as $toolCall) {
            foreach ($this->handleToolCall($conversation, $toolContext, $toolCall, $supersededIdsByName) as $event) {
                if (($event['type'] ?? null) === 'tool_skipped') {
                    $skipped++;
                }

                yield $event;
            }
        }

        /*
         * Every call in this round had already been carried out. Going round again
         * only invites the same repeat, which is the loop we are here to prevent.
         */
        if ($skipped === count($toolCalls)) {
            yield ['type' => 'done', 'message_id' => $assistantMsg->id];

            return;
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

        return $messages
            ->map(fn (Message $message, int $index) => $message->toApiFormat(in_array($index, $withPixels, true)))
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
            yield from $this->failToolCall($conversation, $toolCall['id'], $name, ToolFailure::unknownTool($name));

            return;
        }

        try {
            $args = $this->argumentValidator->validate($tool, is_array($args) ? $args : []);
        } catch (ValidationException $e) {
            yield from $this->failToolCall($conversation, $toolCall['id'], $name, ToolFailure::invalidArguments($e));

            return;
        }

        if (! $tool->authorize($toolContext)) {
            yield from $this->failToolCall($conversation, $toolCall['id'], $name, ToolFailure::unauthorized($tool, $toolContext));

            return;
        }

        $alreadyRun = $this->alreadyRanThisTurn($conversation, $name, $args);

        if ($alreadyRun !== null) {
            /*
             * The model re-issued an action that already went through in this turn.
             * Most often after a confirmation: it sees its own request still standing
             * and asks again, which would loop the user through the same card forever.
             */
            $this->recordToolResult($conversation, $toolCall['id'], $name, [
                'success' => false,
                'error_code' => 'already_done',
                'error' => 'This exact action already ran a moment ago in this turn, so it was not repeated.',
                'previous_result' => json_decode((string) $alreadyRun->content, true),
                'next_step' => 'Tell the user what the earlier result was. Do not call this tool again for the same request.',
            ], Message::STATUS_FAILED);

            yield ['type' => 'tool_skipped', 'tool_call_id' => $toolCall['id'], 'name' => $name];

            return;
        }

        /*
         * A tool with an outstanding question is run without a card: it will
         * only ask, never write. Confirming first would make the user approve
         * an action that then reports itself as failed.
         */
        $hasQuestionFirst = $tool instanceof DefersConfirmation
            && $tool->needsMoreInformation($args, $toolContext);

        if ($tool->requiresConfirmation() && ! $hasQuestionFirst) {
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
                'details' => $tool instanceof ProvidesConfirmationDetails
                    ? $tool->confirmationDetails($args, $toolContext)
                    : [],
            ];

            if (isset($supersededIdsByName[$name])) {
                $event['replaces_message_id'] = $supersededIdsByName[$name];
            }

            yield $event;

            return;
        }

        $result = $this->toolExecutor->handle($tool, $args, $toolContext);

        $this->workspaceResolver->syncFromUser($conversation, $toolContext->user);

        $this->recordToolResult($conversation, $toolCall['id'], $name, $result, Message::STATUS_EXECUTED, $args);

        yield [
            'type' => 'tool_executed',
            'tool_call_id' => $toolCall['id'],
            'name' => $name,
            'result' => $result,
        ];
    }

    /**
     * Has this exact tool call already been carried out since the user last spoke?
     *
     * Scoped to the current turn on purpose: asking for the same thing twice in one
     * breath is always a mistake, while asking again later is a legitimate repeat.
     *
     * @param  array<string, mixed>  $args
     */
    private function alreadyRanThisTurn(Conversation $conversation, string $name, array $args): ?Message
    {
        $turnStartId = $this->turnStartId($conversation);

        return $conversation->messages()
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_EXECUTED)
            ->where('id', '>', $turnStartId)
            ->get()
            ->first(function (Message $message) use ($name, $args) {
                if (($message->metadata['name'] ?? null) !== $name) {
                    return false;
                }

                $previousArgs = $message->metadata['args'] ?? null;

                /*
                 * Results recorded straight from an auto-executed call keep no args,
                 * so a name match within the same turn is enough to treat as a repeat.
                 */
                return $previousArgs === null || $this->sameArguments($previousArgs, $args);
            });
    }

    /**
     * The id of the last message the user actually typed, ignoring the synthetic
     * turns the confirmation flow inserts.
     */
    private function turnStartId(Conversation $conversation): int
    {
        $messages = $conversation->messages()
            ->where('role', 'user')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($messages as $message) {
            if (($message->metadata['synthetic'] ?? false) !== true) {
                return $message->id;
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function sameArguments(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return json_encode($a) === json_encode($b);
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
        ?array $args = null,
    ): void {
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'tool_status' => $status,
            'content' => ToolResultEnvelope::wrap($result),
            'metadata' => $args === null ? ['name' => $name] : ['name' => $name, 'args' => $args],
        ]);
    }

    /**
     * Records the failure and emits it with its reason attached, so the client
     * can show the user what actually happened instead of a generic notice.
     *
     * @param  array<string, mixed>  $failure
     * @return Generator<int, array<string, mixed>>
     */
    private function failToolCall(Conversation $conversation, string $toolCallId, string $name, array $failure): Generator
    {
        $this->recordToolResult($conversation, $toolCallId, $name, $failure, Message::STATUS_FAILED);

        yield [
            'type' => 'tool_failed',
            'tool_call_id' => $toolCallId,
            'name' => $name,
            'error_code' => $failure['error_code'] ?? null,
            'error' => $failure['error'] ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function errorEvent(Throwable $e): array
    {
        $message = match (true) {
            $e instanceof AiProviderException => 'The AI service is not responding right now. Nothing was changed — please try again in a moment.',
            $e instanceof ValidationException => 'I could not work with those details. Could you rephrase what you need?',
            default => 'I hit an unexpected problem and could not finish that. Nothing was changed — please try again.',
        };

        if (app()->environment('local')) {
            $message .= ' ['.$e->getMessage().']';
        }

        return ['type' => 'error', 'message' => $message];
    }
}
