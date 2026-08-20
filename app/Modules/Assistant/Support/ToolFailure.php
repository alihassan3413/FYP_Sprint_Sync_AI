<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Modules\Assistant\Contracts\AssistantTool;
use Illuminate\Validation\ValidationException;

/**
 * Builds the failure payloads the assistant shows the user.
 *
 * Every message has to answer two questions: what went wrong, and what the
 * user can do about it. "Something went wrong" answers neither.
 */
final class ToolFailure
{
    /**
     * @return array<string, mixed>
     */
    public static function unknownTool(string $name): array
    {
        return self::make(
            'unknown_tool',
            "I tried to use an action called \"{$name}\", but it is not available in this workspace. This is a problem on my side, not yours — try rephrasing what you need.",
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function unauthorized(AssistantTool $tool, ToolContext $context): array
    {
        if ($context->workspace === null) {
            return self::make(
                'unauthorized',
                'No workspace is selected, so I cannot do that here. Open a workspace first and ask me again.',
            );
        }

        return self::make(
            'unauthorized',
            sprintf(
                'You do not have permission to %s in %s. This needs workspace admin access, or manager access on at least one project. Ask a workspace admin if you need it.',
                self::describe($tool),
                $context->workspace->name,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function invalidArguments(ValidationException $e): array
    {
        $reasons = collect($e->errors())->flatten()->filter()->unique()->take(3)->implode(' ');

        return self::make(
            'invalid_arguments',
            $reasons === ''
                ? 'I did not have the right details to do that. Could you give me a bit more information?'
                : "I could not do that with the details I had: {$reasons}",
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function executionFailed(AssistantTool $tool): array
    {
        return self::make(
            'execution_failed',
            sprintf(
                'Something broke on our side while trying to %s. Nothing was changed. Please try again in a moment.',
                self::describe($tool),
            ),
        );
    }

    private static function describe(AssistantTool $tool): string
    {
        return match ($tool->name()) {
            'create_workspace' => 'create a workspace',
            'invite_user' => 'invite someone to this workspace',
            'get_workspace_info' => 'read this workspace',
            'list_projects' => 'list projects',
            'create_project' => 'create a project',
            'create_task' => 'create tasks',
            'add_project_member' => 'add someone to a project',
            'list_meetings' => 'list meetings',
            'schedule_meeting' => 'schedule meetings',
            'edit_meeting' => 'change meetings',
            'cancel_meeting' => 'cancel meetings',
            default => 'run that action',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function make(string $code, string $message): array
    {
        return ['success' => false, 'error_code' => $code, 'error' => $message];
    }
}
