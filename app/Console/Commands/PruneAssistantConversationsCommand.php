<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Assistant\Actions\PruneAssistantConversations;
use Illuminate\Console\Command;

final class PruneAssistantConversationsCommand extends Command
{
    protected $signature = 'assistant:prune
        {--conversation-days= : Override the conversation retention window in days}
        {--tool-result-days= : Override the tool result retention window in days}';

    protected $description = 'Delete expired assistant conversations and redact aged tool results.';

    public function handle(PruneAssistantConversations $action): int
    {
        $conversationDays = $this->option('conversation-days');
        $toolResultDays = $this->option('tool-result-days');

        $result = $action->handle(
            $conversationDays === null ? null : (int) $conversationDays,
            $toolResultDays === null ? null : (int) $toolResultDays,
        );

        $this->components->info(sprintf(
            'Deleted %d conversation(s) and %d message(s); redacted %d tool result(s).',
            $result['conversations_deleted'],
            $result['messages_deleted'],
            $result['tool_results_redacted'],
        ));

        return self::SUCCESS;
    }
}
