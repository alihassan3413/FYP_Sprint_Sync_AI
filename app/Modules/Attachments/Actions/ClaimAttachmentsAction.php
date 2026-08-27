<?php

declare(strict_types=1);

namespace App\Modules\Attachments\Actions;

use App\Models\User;
use App\Modules\Attachments\Models\Attachment;
use Illuminate\Database\Eloquent\Model;

final class ClaimAttachmentsAction
{
    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, Attachment>
     */
    public function handle(Model $owner, User $user, array $ids, int $limit, ?int $workspaceId = null): array
    {
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, $limit);

        if ($ids === []) {
            return [];
        }

        $attachments = Attachment::query()
            ->whereIn('id', $ids)
            ->where('uploaded_by', $user->id)
            ->whereNull('attachable_type')
            ->where('workspace_id', $workspaceId ?? $owner->getAttribute('workspace_id'))
            ->get();

        foreach ($attachments as $attachment) {
            $attachment->forceFill([
                'attachable_type' => $owner->getMorphClass(),
                'attachable_id' => $owner->getKey(),
            ])->save();
        }

        return $attachments->all();
    }
}
