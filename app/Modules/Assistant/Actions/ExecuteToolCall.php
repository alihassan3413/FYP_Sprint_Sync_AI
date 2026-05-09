<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use App\Modules\Assistant\Contracts\AssistantTool;
use Throwable;


class ExecuteToolCall
{

    public function handle(AssistantTool $tool, array $args, User $user): array
    {
        $start = microtime(true);

        try {
            if (! $tool->authorize($user)) {
                return [
                    'success' => false,
                    'error_code' => 'unauthorized',
                    'error' => 'You do not have permission to perform this action.',
                ];
            }

            $result = $tool->execute($args, $user);

            $elapsed = (int) ((microtime(true) - $start) * 1000);

            Log::info('Tool executed', [
                'tool' => $tool->name(),
                'user_id' => $user->id,
                'elapsed_ms' => $elapsed,
                'success' => $result['success'] ?? true,
            ]);

            return $result;
        } catch (Throwable $e) {

            Log::error('Tool execution failed', [
                'tool' => $tool->name(),
                'user_id' => $user->id,
                'args' => $args,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error_code' => 'execution_failed',
                'error' => 'The action failed due to a system error. Please try again.',
            ];
        }
    }
}
