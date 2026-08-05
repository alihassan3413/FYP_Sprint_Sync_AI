<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Actions;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExecuteToolCall
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function handle(AssistantTool $tool, array $args, User $user): array
    {
        $startedAt = microtime(true);

        if (! $tool->authorize($user)) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => 'You do not have permission to perform this action.',
            ];
        }

        try {
            $result = $tool->execute($args, $user);
        } catch (Throwable $e) {
            Log::error('Assistant tool execution failed', [
                'tool' => $tool->name(),
                'user_id' => $user->id,
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
            'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'success' => $result['success'] ?? true,
        ]);

        return $result;
    }
}
