<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\Teams\Actions\BuildTeamRosterAction;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One row per human on the People page.
 *
 * Everyone who can see the team gets the roster (members, then pending
 * invitations) exactly as before. Viewers who may see People profiles also get
 * job title and department merged into those rows, plus people who have no
 * SprintSync access at all, so nobody is listed twice:
 *
 * - person linked to a member          → the member's row
 * - person whose email has an invite   → the invitation's row
 * - person whose email matches an
 *   unlinked member                    → the member's row, flagged so it can be linked
 * - anyone else                        → a "no access" row
 */
final class BuildPeopleDirectoryAction
{
    public function __construct(private readonly BuildTeamRosterAction $roster) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace, User $viewer, bool $withProfiles): Collection
    {
        $roster = $this->roster->handle($workspace, $viewer);

        if (! $withProfiles) {
            return $roster;
        }

        $people = $workspace->people()->with('department')->orderBy('name')->get();
        $linkedUserIds = $people->pluck('user_id')->filter()->all();

        $rows = $roster->keyBy('id')->map(fn (array $row) => [...$row, 'person' => null, 'link_suggested' => false]);
        $unmatched = collect();

        foreach ($people as $person) {
            $key = $this->matchingRowKey($person, $rows, $linkedUserIds);

            if ($key === null) {
                $unmatched->push($this->withoutAccess($person));

                continue;
            }

            $rows[$key] = [
                ...$rows[$key],
                'name' => $person->name,
                'person' => $this->summary($person),
                'link_suggested' => $rows[$key]['status'] === 'active' && $person->user_id === null,
            ];
        }

        return $rows->values()->concat($unmatched)->values();
    }

    /**
     * The directory row for a single person, for their profile page.
     *
     * @return array<string, mixed>
     */
    public function forPerson(Workspace $workspace, User $viewer, Person $person): array
    {
        return $this->handle($workspace, $viewer, true)
            ->first(fn (array $row) => ($row['person']['public_id'] ?? null) === $person->public_id);
    }

    /**
     * @param  Collection<int|string, array<string, mixed>>  $rows
     * @param  array<int, int>  $linkedUserIds
     */
    private function matchingRowKey(Person $person, Collection $rows, array $linkedUserIds): int|string|null
    {
        $free = $rows->filter(fn (array $row) => $row['person'] === null);

        if ($person->user_id !== null && $free->has($person->user_id)) {
            return $person->user_id;
        }

        if ($person->user_id !== null || $person->email === null) {
            return null;
        }

        $email = Str::lower($person->email);

        return $free->search(fn (array $row) => Str::lower($row['email']) === $email
            && $row['role'] !== UserRole::CLIENT->value
            && ($row['status'] === 'pending' || ! in_array($row['id'], $linkedUserIds, true))) ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    private function withoutAccess(Person $person): array
    {
        return [
            'id' => "person-{$person->public_id}",
            'name' => $person->name,
            'email' => $person->email ?? '',
            'role' => null,
            'workspace_role_id' => null,
            'workspace_role_name' => null,
            'status' => 'none',
            'last_active_at' => null,
            'avatar_url' => null,
            'is_self' => false,
            'invite_url' => null,
            'person' => $this->summary($person),
            'link_suggested' => false,
        ];
    }

    /**
     * @return array{public_id: string, title: ?string, department: ?string}
     */
    private function summary(Person $person): array
    {
        return [
            'public_id' => $person->public_id,
            'title' => $person->title,
            'department' => $person->department?->name,
        ];
    }
}
