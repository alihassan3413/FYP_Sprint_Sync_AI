<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Modules\Assistant\Actions\ResolveConversationWorkspace;
use App\Modules\Assistant\Http\Requests\CommandCatalogRequest;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Support\CommandCatalog;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;

final class CommandCatalogController
{
    public function __invoke(
        CommandCatalogRequest $request,
        ToolRegistry $registry,
        CommandCatalog $catalog,
        ResolveConversationWorkspace $workspaceResolver,
    ): JsonResponse {
        $user = $request->user();
        $workspace = $this->resolveWorkspace($request, $workspaceResolver);

        $context = new ToolContext($user, $workspace);

        return response()->json([
            'workspace_id' => $workspace?->id,
            'commands' => $catalog->forTools($registry->availableFor($context)),
        ]);
    }

    private function resolveWorkspace(
        CommandCatalogRequest $request,
        ResolveConversationWorkspace $workspaceResolver,
    ): ?Workspace {
        $user = $request->user();
        $conversationId = $request->input('conversation_id');

        if ($conversationId !== null) {
            $conversation = Conversation::query()
                ->where('user_id', $user->id)
                ->find($conversationId);

            if ($conversation !== null) {
                return $workspaceResolver->contextFor($conversation, $user)->workspace;
            }
        }

        $workspaceId = $request->input('workspace_id') ?? $user->current_workspace_id;

        if ($workspaceId === null) {
            return null;
        }

        return Workspace::query()
            ->whereKey($workspaceId)
            ->whereHas('users', fn ($query) => $query->whereKey($user->id))
            ->first();
    }
}
