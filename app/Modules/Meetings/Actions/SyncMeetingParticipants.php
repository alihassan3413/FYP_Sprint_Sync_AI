<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingParticipant;
use App\Modules\Projects\Models\Project;
use App\UserRole;
use Illuminate\Support\Collection;

final class SyncMeetingParticipants
{
    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, string>  $emails
     * @return array{added: Collection<int, MeetingParticipant>, removed: Collection<int, MeetingParticipant>}
     */
    public function handle(Meeting $meeting, Project $project, array $userIds, array $emails): array
    {
        $rows = $this->buildRows($project, $userIds, $emails);

        $existing = $meeting->participants()->get()->keyBy('email');
        $desired = $rows->keyBy('email');

        $removed = $existing->reject(fn (MeetingParticipant $participant) => $desired->has($participant->email))->values();

        if ($removed->isNotEmpty()) {
            $meeting->participants()->whereIn('id', $removed->pluck('id'))->delete();
        }

        $added = collect();

        foreach ($desired as $email => $row) {
            if ($existing->has($email)) {
                $existing->get($email)->update(['user_id' => $row['user_id'], 'name' => $row['name']]);

                continue;
            }

            $added->push($meeting->participants()->create($row));
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, string>  $emails
     * @return Collection<int, array<string, mixed>>
     */
    private function buildRows(Project $project, array $userIds, array $emails): Collection
    {
        $users = $this->assignableUsers($project, $userIds);

        $rows = $users->map(fn (User $user) => [
            'user_id' => $user->id,
            'email' => MeetingParticipant::normaliseEmail($user->email),
            'name' => $user->name,
        ]);

        $takenEmails = $rows->pluck('email')->all();

        foreach ($emails as $email) {
            $normalised = MeetingParticipant::normaliseEmail((string) $email);

            if ($normalised === '' || in_array($normalised, $takenEmails, true)) {
                continue;
            }

            $takenEmails[] = $normalised;

            $existingUser = User::query()->whereRaw('lower(email) = ?', [$normalised])->first();

            $rows->push([
                'user_id' => $existingUser !== null && $this->isAssignable($project, $existingUser) ? $existingUser->id : null,
                'email' => $normalised,
                'name' => $existingUser?->name,
            ]);
        }

        return $rows->values();
    }

    /**
     * @param  array<int, int>  $userIds
     * @return Collection<int, User>
     */
    private function assignableUsers(Project $project, array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', array_unique($userIds))
            ->get()
            ->filter(fn (User $user) => $this->isAssignable($project, $user))
            ->values();
    }

    private function isAssignable(Project $project, User $user): bool
    {
        return $project->workspace->userHasAtLeast($user, UserRole::ADMIN)
            || $project->hasMember($user);
    }
}
