<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Models\User;
use App\Modules\Assistant\Actions\ProcessChatMessage;
use App\Modules\Assistant\Http\Requests\ChatMessageRequest;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Support\EventStream;
use App\Modules\Assistant\Support\UsageGuard;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ChatController
{
    public function __invoke(
        ChatMessageRequest $request,
        ProcessChatMessage $processor,
        UsageGuard $usageGuard,
    ): StreamedResponse {
        $user = $request->user();
        $usageGuard->ensureWithinDailyBudget($user);

        $conversation = $this->resolveConversation($user, $request);
        $model = $request->input('model') ?? config('assistant.default_model');
        $message = $request->string('message')->toString();
        $pageContext = $request->array('page_context');

        return EventStream::respond(function (EventStream $stream) use (
            $processor,
            $user,
            $conversation,
            $message,
            $pageContext,
            $model,
        ) {
            $stream->emit(['type' => 'connected', 'conversation_id' => $conversation->id]);

            $events = $processor->handle(
                user: $user,
                conversation: $conversation,
                userMessage: $message,
                pageContext: $pageContext,
                model: $model,
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

    private function resolveConversation(User $user, ChatMessageRequest $request): Conversation
    {
        $workspaceId = $request->input('workspace_id')
            ?? $request->input('page_context.workspace_id')
            ?? $user->current_workspace_id;

        $conversationId = $request->input('conversation_id');

        if ($conversationId === null) {
            return Conversation::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
            ]);
        }

        $conversation = Conversation::query()
            ->where('user_id', $user->id)
            ->findOrFail($conversationId);

        if ($conversation->workspace_id === null && $workspaceId !== null) {
            $conversation->update(['workspace_id' => $workspaceId]);
        }

        return $conversation;
    }
}
