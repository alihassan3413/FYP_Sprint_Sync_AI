<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Support\Str;

/**
 * When someone joins a workspace, attach them to the person profile that was
 * waiting for them: the one unlinked person with the same email. With no
 * match, or more than one, nothing is guessed; the owner can link by hand.
 * Guest clients are customers, never staff profiles.
 */
final class LinkJoiningMemberAction
{
    public function __construct(private readonly SetPersonUserAction $linker) {}

    public function handle(Workspace $workspace, User $member): void
    {
        $joinedAsGuest = $workspace->users()->whereKey($member->id)->wherePivot('role', UserRole::CLIENT->value)->exists();

        if ($joinedAsGuest || $workspace->people()->where('user_id', $member->id)->exists()) {
            return;
        }

        $candidates = $workspace->people()
            ->whereNull('user_id')
            ->whereRaw('lower(email) = ?', [Str::lower($member->email)])
            ->limit(2)
            ->get();

        if ($candidates->count() !== 1) {
            return;
        }

        $person = $candidates->sole();

        $this->linker->handle(
            $person,
            $member,
            $member,
            "{$member->name} joined SprintSync and was connected to {$person->name}.",
        );
    }
}
