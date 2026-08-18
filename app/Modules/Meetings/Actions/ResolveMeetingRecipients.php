<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingParticipant;
use Illuminate\Support\Collection;

final class ResolveMeetingRecipients
{
    /**
     * @return Collection<int, User>
     */
    public function handle(Meeting $meeting, User $actor): Collection
    {
        return $meeting->participants()
            ->internal()
            ->with('user')
            ->get()
            ->map(fn (MeetingParticipant $participant) => $participant->user)
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $actor->id)
            ->values();
    }

    /**
     * @return Collection<int, MeetingParticipant>
     */
    public function externals(Meeting $meeting): Collection
    {
        return $meeting->participants()->external()->get()->values();
    }
}
