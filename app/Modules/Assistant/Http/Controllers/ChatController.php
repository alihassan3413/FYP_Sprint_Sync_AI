<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Modules\Assistant\Actions\ProcessChatMessage;
use App\Modules\Assistant\Http\Requests\ChatMessageRequest;
use App\Modules\Assistant\Models\Conversation;
use Symfony\Component\HttpFoundation\StreamedResponse;


class ChatController
{
    public function __invoke(
        ChatMessageRequest $request,
        ProcessChatMessage $processor,
    ): StreamedResponse {
        $user = $request->user();
        $validated = $request->validated();


        $conversation = $this->resolveConversation($user, $validated);

        $model = $validated['model'] ?? config('assistant.default_model');

        return new StreamedResponse(
            function () use ($processor, $user, $conversation, $validated, $model) {
                // Disable PHP's output buffering so each event flushes.
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                // Tell PHP the connection should NOT abort if the client
                // closes early — we want to finish writing to the DB.
                ignore_user_abort(true);

                // Long-running response: lift the time limit defensively.
                set_time_limit(0);

                // Send an initial event so the client knows the stream is
                // alive even before LLM responds.
                $this->emit(['type' => 'connected', 'conversation_id' => $conversation->id]);

                foreach ($processor->handle(
                    user: $user,
                    conversation: $conversation,
                    userMessage: $validated['message'],
                    pageContext: $validated['page_context'] ?? [],
                    model: $model,
                ) as $event) {
                    if (connection_aborted()) {
                        return;
                    }

                    $this->emit($event);
                }

                $this->emit(['type' => 'stream_end']);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                // Critical for nginx — disables proxy buffering.
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function resolveConversation($user, array $validated): Conversation
    {
        if (! empty($validated['conversation_id'])) {
            $conversation = Conversation::where('user_id', $user->id)
                ->findOrFail($validated['conversation_id']);

            return $conversation;
        }

        return Conversation::create([
            'user_id' => $user->id,
            'workspace_id' => $validated['workspace_id'] ?? null,
        ]);
    }

    private function emit(array $event): void
    {
        echo 'data: ' . json_encode($event) . "\n\n";
        @ob_flush();
        flush();
    }
}
