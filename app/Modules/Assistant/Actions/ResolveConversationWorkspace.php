<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Actions;

use App\Models\User;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Workspace\Models\Workspace;

final class ResolveConversationWorkspace
{
    public function handle(Conversation $conversation, User $user): ?Workspace
    {
        if ($conversation->workspace_id === null) {
            return null;
        }

        $workspace = Workspace::find($conversation->workspace_id);

        if ($workspace === null || ! $workspace->hasMember($user)) {
            $conversation->update(['workspace_id' => null]);

            return null;
        }

        return $workspace;
    }

    public function contextFor(Conversation $conversation, User $user): ToolContext
    {
        return new ToolContext($user, $this->handle($conversation, $user));
    }

    public function syncFromUser(Conversation $conversation, User $user): void
    {
        $currentWorkspaceId = $user->fresh()?->current_workspace_id;

        if ($currentWorkspaceId === null || $currentWorkspaceId === $conversation->workspace_id) {
            return;
        }

        $conversation->update(['workspace_id' => $currentWorkspaceId]);
    }
}
