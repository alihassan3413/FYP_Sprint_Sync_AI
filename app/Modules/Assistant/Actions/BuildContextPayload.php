<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Actions;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;

class BuildContextPayload
{
    /**
     * @param  array{
     *     page?: string,
     *     route?: string,
     *     workspace_id?: int|null,
     *     workspace_slug?: string|null,
     *     workspace_name?: string|null
     * }  $pageContext optional UI context
     * @param  array<int, array{name: string, args: array}>  $supersededActions tools the user was amending or canceling
     * @return array{system: string, additional_messages: array}
     */
    public function handle(
        User $user,
        ?Workspace $workspace,
        array $pageContext = [],
        array $supersededActions = [],
    ): array {
        $systemPrompt = $this->buildSystemPrompt($user, $workspace, $pageContext, $supersededActions);

        return [
            'system' => $systemPrompt,
            'additional_messages' => [],
        ];
    }

    /**
     * @param  array<int, array{name: string, args: array}>  $supersededActions
     */
    private function buildSystemPrompt(
        User $user,
        ?Workspace $workspace,
        array $pageContext,
        array $supersededActions = [],
    ): string {
        $parts = [];

        $parts[] = <<<'TXT'
You are SprintSync's in-app assistant. You help users manage their
workspaces, projects, sprints, tasks, and team. You can take actions
on the user's behalf using the provided tools.
TXT;

        $parts[] = <<<'TXT'
Rules:
- Be concise. Aim for 1-3 sentences unless the user asks for detail.
- When a user asks for an action you can perform with a tool, call the tool. Do not narrate what you are about to do — just do it.
- If a tool requires confirmation, the system handles that — you don't need to ask "are you sure?" before calling.
- If you don't have enough information to call a tool, ask ONE clarifying question. Don't ask multiple questions at once.
- If you cannot help with something, say so directly. Don't pretend or invent capabilities.
- Never invent IDs, names, counts, members, projects, tasks, or workspace data. If you don't know, look it up with a tool or ask the user.
- When the user asks about the current workspace, workspace details, member count, admins, members list, pending invitations, or their role in the workspace, use the get_workspace_info tool.
- For simple workspace count or summary questions, call get_workspace_info without members/invitations unless needed.
- If the user asks who the members are, list members, show admins, or show team members, call get_workspace_info with include_members=true.
- If the user asks about pending invitations, invites, or invited users, call get_workspace_info with include_invitations=true.
- get_workspace_info is read-only, so do not ask for confirmation before using it.
- If the user cancels or rejects a tool confirmation, acknowledge the cancellation once and do not call the same tool again unless the user clearly asks to try again.
TXT;

        $parts[] = sprintf(
            "Current user: %s (%s). User ID: %d.",
            $user->name,
            $user->email,
            $user->id,
        );

        if ($workspace) {
            $membership = $workspace->users()
                ->whereKey($user->id)
                ->first();

            $parts[] = sprintf(
                "Current workspace: '%s' (ID: %d, slug: %s). Current user's workspace role: %s. Workspace was created %s.",
                $workspace->name,
                $workspace->id,
                $workspace->slug,
                $membership?->pivot?->role ?? 'unknown',
                $workspace->created_at?->diffForHumans() ?? 'recently',
            );
        } else {
            $parts[] = "The user has no active workspace selected.";
        }

        if (! empty($pageContext['page'])) {
            $parts[] = "The user is currently viewing: {$pageContext['page']}.";
        }

        if (! empty($pageContext['route'])) {
            $parts[] = "Current route: {$pageContext['route']}.";
        }

        if (! empty($pageContext['workspace_name']) || ! empty($pageContext['workspace_slug'])) {
            $parts[] = sprintf(
                "UI selected workspace from page context: name=%s, slug=%s, id=%s.",
                $pageContext['workspace_name'] ?? 'unknown',
                $pageContext['workspace_slug'] ?? 'unknown',
                $pageContext['workspace_id'] ?? 'unknown',
            );
        }

        $parts[] = sprintf(
            "Current date/time: %s. Today is %s.",
            now()->toIso8601String(),
            now()->format('l, F j, Y'),
        );

        if (! empty($supersededActions)) {
            $lines = ['Pending action context:'];
            foreach ($supersededActions as $action) {
                $argsJson = json_encode($action['args'] ?? [], JSON_UNESCAPED_SLASHES);
                $lines[] = sprintf('- %s with args %s', $action['name'], $argsJson);
            }
            $lines[] = 'The user just sent a new message while the action(s) above were awaiting confirmation. Their message is most likely (a) amending the args, (b) canceling, or (c) unrelated.';
            $lines[] = 'If amending: re-emit the SAME tool with the args merged from above (keep prior fields, override only what the user changed). Do not narrate.';
            $lines[] = 'If canceling: respond with one short sentence acknowledging the cancellation. Do not call the tool again.';
            $lines[] = 'If unrelated: handle the new request normally.';
            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }
}
