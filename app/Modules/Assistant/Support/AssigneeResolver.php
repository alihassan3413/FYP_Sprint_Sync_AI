<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\UserRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns "assign it to Rana" into a user, without the caller needing an exact
 * email address. Only people who can actually hold the task are considered:
 * project members plus workspace admins, never clients.
 */
final class AssigneeResolver
{
    private const DECISIVE_SCORE = 60;

    /**
     * Names are short, so the generous task-search floor produces nonsense here:
     * "rana" is 26% similar to "Ada Owner" by character overlap alone.
     */
    private const NAME_FLOOR = 45;

    private const DECISIVE_GAP = 15;

    public function resolve(Project $project, string $query): AssigneeResolution
    {
        $query = trim($query);
        $assignable = $this->assignableUsers($project);

        if ($query === '') {
            return AssigneeResolution::notFound($this->summarise($assignable), 'No person was named.');
        }

        if (filter_var($query, FILTER_VALIDATE_EMAIL) !== false) {
            return $this->byEmail($project, $assignable, $query);
        }

        return $this->byName($project, $assignable, $query);
    }

    /**
     * @param  Collection<int, User>  $assignable
     */
    private function byEmail(Project $project, Collection $assignable, string $email): AssigneeResolution
    {
        $needle = mb_strtolower($email);
        $match = $assignable->first(fn (User $user) => mb_strtolower($user->email) === $needle);

        if ($match !== null) {
            return AssigneeResolution::resolved($match);
        }

        $inWorkspace = $project->workspace->users()->whereRaw('lower(email) = ?', [$needle])->first();

        if ($inWorkspace !== null && ! $project->workspace->isClient($inWorkspace)) {
            return AssigneeResolution::notOnProject(
                $inWorkspace,
                "{$inWorkspace->name} is in this workspace but not on \"{$project->name}\" yet, "
                    .'so the task cannot be assigned to them.',
            );
        }

        return AssigneeResolution::notFound(
            $this->summarise($assignable),
            "Nobody on \"{$project->name}\" has the address {$email}.",
        );
    }

    /**
     * @param  Collection<int, User>  $assignable
     */
    private function byName(Project $project, Collection $assignable, string $name): AssigneeResolution
    {
        $ranked = FuzzyMatcher::rank(
            $name,
            $assignable,
            fn (User $user) => [$user->name, Str::before($user->email, '@')],
            self::NAME_FLOOR,
        );

        if ($ranked === []) {
            return $this->fallbackToWorkspace($project, $assignable, $name);
        }

        $top = $ranked[0];
        $runnerUp = $ranked[1]['score'] ?? 0;

        $isDecisive = count($ranked) === 1
            || ($top['score'] >= self::DECISIVE_SCORE && ($top['score'] - $runnerUp) >= self::DECISIVE_GAP);

        if ($isDecisive) {
            return AssigneeResolution::resolved($top['item']);
        }

        return AssigneeResolution::ambiguous(
            array_map(
                fn (array $row) => [
                    'id' => $row['item']->id,
                    'name' => UntrustedText::inline($row['item']->name),
                    'email' => UntrustedText::inline($row['item']->email),
                    'match_confidence' => $row['score'],
                ],
                array_slice($ranked, 0, 5),
            ),
            "More than one person on \"{$project->name}\" could be \"{$name}\".",
        );
    }

    /**
     * Nobody on the project matched — see whether the workspace has them, so the
     * assistant can offer to add them instead of just failing.
     *
     * @param  Collection<int, User>  $assignable
     */
    private function fallbackToWorkspace(Project $project, Collection $assignable, string $name): AssigneeResolution
    {
        $workspaceMembers = $project->workspace->users()
            ->get()
            ->reject(fn (User $user) => $project->workspace->isClient($user));

        $ranked = FuzzyMatcher::rank($name, $workspaceMembers, fn (User $user) => [$user->name], self::NAME_FLOOR);

        if ($ranked !== [] && count($ranked) === 1) {
            $candidate = $ranked[0]['item'];

            return AssigneeResolution::notOnProject(
                $candidate,
                "{$candidate->name} is in this workspace but not on \"{$project->name}\" yet, "
                    .'so the task cannot be assigned to them.',
            );
        }

        return AssigneeResolution::notFound(
            $this->summarise($assignable),
            "Nobody on \"{$project->name}\" matches \"{$name}\".",
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function assignableUsers(Project $project): Collection
    {
        $members = $project->members()->get();

        $admins = $project->workspace->users()
            ->wherePivotIn('role', [UserRole::OWNER->value, UserRole::ADMIN->value])
            ->get();

        return $members
            ->concat($admins)
            ->unique('id')
            ->reject(fn (User $user) => $project->workspace->isClient($user))
            ->values();
    }

    /**
     * @param  Collection<int, User>  $assignable
     * @return array<int, array{id: int, name: string|null, email: string|null}>
     */
    private function summarise(Collection $assignable): array
    {
        return $assignable
            ->take(25)
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => UntrustedText::inline($user->name),
                'email' => UntrustedText::inline($user->email),
            ])
            ->values()
            ->all();
    }
}
