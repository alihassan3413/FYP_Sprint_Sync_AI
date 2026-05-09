<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use Illuminate\Http\Request;
use App\Modules\Assistant\Actions\ExecuteToolCall;
use App\Modules\Assistant\Actions\ProcessChatMessage;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Tools\ToolRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConfirmActionController
{
    public function __invoke(
        Request $request,
        ToolRegistry $registry,
        ExecuteToolCall $executor,
        ProcessChatMessage $processor,
    ): StreamedResponse {
        $validated = $request->validate([
            'message_id' => 'required|integer|exists:assistant_messages,id',
            'action' => 'required|in:confirm,reject',
        ]);

        $user = $request->user();

        $pendingMessage = Message::where('id', $validated['message_id'])
            ->where('tool_status', Message::STATUS_PENDING)
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id))
            ->firstOrFail();

        $conversation = $pendingMessage->conversation;
        $toolName = $pendingMessage->metadata['name'] ?? null;
        $args = $pendingMessage->metadata['args'] ?? [];

        return new StreamedResponse(
            function () use (
                $validated,
                $pendingMessage,
                $conversation,
                $registry,
                $executor,
                $processor,
                $user,
                $toolName,
                $args,
            ) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                ignore_user_abort(true);
                set_time_limit(0);

                if ($validated['action'] === 'reject') {
                    // User canceled. Update the pending message.
                    $pendingMessage->update([
                        'tool_status' => Message::STATUS_REJECTED,
                        'content' => json_encode([
                            'success' => false,
                            'error' => 'User canceled this action.',
                        ]),
                    ]);

                    $this->emit(['type' => 'tool_rejected', 'message_id' => $pendingMessage->id]);
                } else {
                    // User confirmed. Execute the tool.
                    $tool = $registry->get($toolName);

                    if (! $tool) {
                        $this->emit(['type' => 'error', 'message' => 'Tool no longer available.']);
                        return;
                    }

                    $result = $executor->handle($tool, $args, $user);

                    $pendingMessage->update([
                        'tool_status' => $result['success'] ?? false
                            ? Message::STATUS_EXECUTED
                            : Message::STATUS_FAILED,
                        'content' => json_encode($result),
                    ]);

                    $this->emit([
                        'type' => 'tool_executed',
                        'message_id' => $pendingMessage->id,
                        'result' => $result,
                    ]);
                }


                foreach ($processor->handle(
                    user: $user,
                    conversation: $conversation,
                    userMessage: '[User responded via confirmation UI]',
                    pageContext: [],
                ) as $event) {
                    if (connection_aborted()) return;
                    $this->emit($event);
                }

                $this->emit(['type' => 'stream_end']);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function emit(array $event): void
    {
        echo 'data: ' . json_encode($event) . "\n\n";
        @ob_flush();
        flush();
    }
}
