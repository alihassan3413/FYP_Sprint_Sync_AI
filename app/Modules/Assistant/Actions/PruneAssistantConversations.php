<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Actions;

use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Assistant\Support\ToolResultEnvelope;
use Illuminate\Support\Collection;

final class PruneAssistantConversations
{
    public const REDACTION_REASON = 'This tool result was removed by the assistant data retention policy. '
        .'Ask the user to run the relevant tool again if you need the current values.';

    private const REDACTION_MARKER = '"redacted":true';

    private const CHUNK_SIZE = 500;

    /**
     * @return array{conversations_deleted: int, messages_deleted: int, tool_results_redacted: int}
     */
    public function handle(?int $conversationDays = null, ?int $toolResultDays = null): array
    {
        $conversationDays ??= (int) config('assistant.retention.conversation_days');
        $toolResultDays ??= (int) config('assistant.retention.tool_result_days');

        $deleted = $this->deleteExpiredConversations($conversationDays);

        return [
            'conversations_deleted' => $deleted['conversations'],
            'messages_deleted' => $deleted['messages'],
            'tool_results_redacted' => $this->redactAgedToolResults($toolResultDays),
        ];
    }

    /**
     * @return array{conversations: int, messages: int}
     */
    private function deleteExpiredConversations(int $days): array
    {
        if ($days <= 0) {
            return ['conversations' => 0, 'messages' => 0];
        }

        $cutoff = now()->subDays($days);
        $conversations = 0;
        $messages = 0;

        Conversation::query()
            ->where('updated_at', '<', $cutoff)
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $batch) use (&$conversations, &$messages) {
                $ids = $batch->pluck('id')->all();

                $messages += Message::query()->whereIn('conversation_id', $ids)->delete();
                $conversations += Conversation::query()->whereIn('id', $ids)->delete();
            });

        return ['conversations' => $conversations, 'messages' => $messages];
    }

    private function redactAgedToolResults(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return Message::query()
            ->where('role', 'tool')
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotNull('content')
            ->where('content', 'not like', '%'.self::REDACTION_MARKER.'%')
            ->update([
                'content' => ToolResultEnvelope::wrap([
                    'redacted' => true,
                    'reason' => self::REDACTION_REASON,
                ]),
            ]);
    }
}
