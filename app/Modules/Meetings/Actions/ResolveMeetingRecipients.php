<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use Illuminate\Support\Collection;

final class ResolveMeetingRecipients
{
    /**
     * @return Collection<int, User>
     */
    public function handle(Meeting $meeting, User $actor): Collection
    {
        return $meeting->project->members()
            ->get()
            ->unique('id')
            ->reject(fn (User $member) => $member->id === $actor->id)
            ->values();
    }
}
