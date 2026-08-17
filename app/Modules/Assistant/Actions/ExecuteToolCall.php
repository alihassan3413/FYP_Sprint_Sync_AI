<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Actions;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExecuteToolCall
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function handle(AssistantTool $tool, array $args, ToolContext $context): array
    {
        $startedAt = microtime(true);
        $user = $context->user;

        if (! $tool->authorize($context)) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => 'You do not have permission to perform this action.',
            ];
        }

        try {
            $result = $tool->execute($args, $context);
        } catch (Throwable $e) {
            Log::error('Assistant tool execution failed', [
                'tool' => $tool->name(),
                'user_id' => $user->id,
                'workspace_id' => $context->workspace?->id,
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'error_code' => 'execution_failed',
                'error' => 'The action failed due to a system error. Please try again.',
            ];
        }

        Log::info('Assistant tool executed', [
            'tool' => $tool->name(),
            'user_id' => $user->id,
            'workspace_id' => $context->workspace?->id,
            'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'success' => $result['success'] ?? true,
        ]);

        return $result;
    }
}
