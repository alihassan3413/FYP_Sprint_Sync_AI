<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;

/**
 * When someone leaves a workspace, the person they were linked to stays (they
 * may still be on the books) but no longer points at a login outside the
 * workspace. Called by the Teams module when a member is removed.
 */
final class UnlinkDepartedMemberAction
{
    public function __construct(private readonly SetPersonUserAction $linker) {}

    public function handle(Workspace $workspace, User $member, User $actor): void
    {
        $workspace->people()->where('user_id', $member->id)->get()->each(
            fn ($person) => $this->linker->handle(
                $person,
                null,
                $actor,
                "{$person->name} was disconnected from {$member->name}, who was removed from the workspace.",
            ),
        );
    }
}
