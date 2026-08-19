<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Models\User;
use App\Modules\Assistant\Actions\ExecuteToolCall;
use App\Modules\Assistant\Actions\ProcessChatMessage;
use App\Modules\Assistant\Actions\ResolveConversationWorkspace;
use App\Modules\Assistant\Http\Requests\ConfirmActionRequest;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Support\EventStream;
use App\Modules\Assistant\Support\ToolArgumentValidator;
use App\Modules\Assistant\Support\ToolResultEnvelope;
use App\Modules\Assistant\Tools\ToolRegistry;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ConfirmActionController
{
    public function __invoke(
        ConfirmActionRequest $request,
        ToolRegistry $registry,
        ExecuteToolCall $executor,
        ProcessChatMessage $processor,
        ToolArgumentValidator $argumentValidator,
        ResolveConversationWorkspace $workspaceResolver,
    ): StreamedResponse {
        $user = $request->user();
        $pendingMessage = $request->pendingMessage();
        $conversation = $pendingMessage->conversation;
        $confirmed = $request->isConfirmation();

        return EventStream::respond(function (EventStream $stream) use (
            $argumentValidator,
            $confirmed,
            $conversation,
            $executor,
            $pendingMessage,
            $processor,
            $registry,
            $user,
            $workspaceResolver,
        ) {
            $confirmed
                ? $this->execute($stream, $registry, $executor, $argumentValidator, $workspaceResolver, $pendingMessage, $conversation, $user)
                : $this->reject($stream, $pendingMessage);

            /*
             * The model still needs a turn to tell the user how it went, but it must
             * not treat this as a fresh instruction: without saying so plainly it
             * re-proposes the action it just carried out.
             */
            $events = $processor->handle(
                user: $user,
                conversation: $conversation,
                userMessage: $confirmed
                    ? '[System] The user confirmed the action above and it has already been carried out. '
                        .'Its result is in the tool message. Reply with one short sentence telling them what happened. '
                        .'Do not call that tool again, and do not propose the same action.'
                    : '[System] The user canceled the action above and nothing was done. '
                        .'Acknowledge that in one short sentence and wait for their next instruction. '
                        .'Do not propose the same action again.',
                synthetic: true,
            );

            foreach ($events as $event) {
                if ($stream->aborted()) {
                    return;
                }

                $stream->emit($event);
            }

            $stream->emit(['type' => 'stream_end']);
        });
    }

    private function reject(EventStream $stream, Message $pendingMessage): void
    {
        $pendingMessage->update([
            'tool_status' => Message::STATUS_REJECTED,
            'content' => json_encode(['success' => false, 'error' => 'User canceled this action.']),
        ]);

        $stream->emit(['type' => 'tool_rejected', 'message_id' => $pendingMessage->id]);
    }

    private function execute(
        EventStream $stream,
        ToolRegistry $registry,
        ExecuteToolCall $executor,
        ToolArgumentValidator $argumentValidator,
        ResolveConversationWorkspace $workspaceResolver,
        Message $pendingMessage,
        Conversation $conversation,
        User $user,
    ): void {
        $tool = $registry->get($pendingMessage->metadata['name'] ?? '');

        if ($tool === null) {
            $this->fail($stream, $pendingMessage, 'That action is no longer available.');

            return;
        }

        try {
            $args = $argumentValidator->validate($tool, $pendingMessage->metadata['args'] ?? []);
        } catch (ValidationException) {
            $this->fail($stream, $pendingMessage, 'The requested action had invalid details. Please ask again.');

            return;
        }

        $result = $executor->handle($tool, $args, $workspaceResolver->contextFor($conversation, $user));

        $workspaceResolver->syncFromUser($conversation, $user);

        $pendingMessage->update([
            'tool_status' => ($result['success'] ?? false) ? Message::STATUS_EXECUTED : Message::STATUS_FAILED,
            'content' => ToolResultEnvelope::wrap($result),
        ]);

        $stream->emit([
            'type' => 'tool_executed',
            'message_id' => $pendingMessage->id,
            'result' => $result,
        ]);
    }

    private function fail(EventStream $stream, Message $pendingMessage, string $error): void
    {
        $pendingMessage->update([
            'tool_status' => Message::STATUS_FAILED,
            'content' => json_encode(['success' => false, 'error' => $error]),
        ]);

        $stream->emit(['type' => 'tool_failed', 'message_id' => $pendingMessage->id, 'error' => $error]);
    }
}
