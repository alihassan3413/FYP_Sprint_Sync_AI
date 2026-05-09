<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Actions;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;


class BuildContextPayload
{
    /**
     * @param array{page?: string, route?: string} $pageContext  optional UI context
     * @return array{system: string, additional_messages: array}
     */
    public function handle(User $user, ?Workspace $workspace, array $pageContext = []): array
    {
        $systemPrompt = $this->buildSystemPrompt($user, $workspace, $pageContext);

        return [
            'system' => $systemPrompt,
            'additional_messages' => [],
        ];
    }

    private function buildSystemPrompt(User $user, ?Workspace $workspace, array $pageContext): string
    {
        $parts = [];

        $parts[] = <<<'TXT'
        You are SprintSync's in-app assistant. You help users manage their
        workspaces, projects, sprints, tasks, and team. You can take actions
        on the user's behalf using the provided tools.
        TXT;

        $parts[] = <<<'TXT'
        Rules:
        - Be concise. Aim for 1-3 sentences unless the user asks for detail.
        - When a user asks for an action you can perform with a tool, call
          the tool. Do not narrate what you are about to do — just do it.
        - If a tool requires confirmation, the system handles that — you
          don't need to ask "are you sure?" before calling.
        - If you don't have enough information to call a tool, ask ONE
          clarifying question. Don't ask multiple questions at once.
        - If you cannot help with something, say so directly. Don't
          pretend or invent capabilities.
        - Never invent IDs, names, or data. If you don't know, look it up
          with a tool or ask the user.
        TXT;

        $parts[] = sprintf(
            "Current user: %s (%s). User ID: %d.",
            $user->name,
            $user->email,
            $user->id,
        );

        if ($workspace) {
            $parts[] = sprintf(
                "Current workspace: '%s' (ID: %d). Workspace was created %s.",
                $workspace->name,
                $workspace->id,
                $workspace->created_at?->diffForHumans() ?? 'recently',
            );
        } else {
            $parts[] = "The user has no active workspace selected.";
        }

        if (! empty($pageContext['page'])) {
            $parts[] = "The user is currently viewing: {$pageContext['page']}.";
        }

        $parts[] = sprintf(
            "Current date/time: %s. Today is %s.",
            now()->toIso8601String(),
            now()->format('l, F j, Y'),
        );

        return implode("\n\n", $parts);
    }
}
