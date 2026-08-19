<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Modules\Projects\Models\Project;
use Illuminate\Support\Collection;

/**
 * Works out which project a request is about, so tools do not each invent their
 * own rules. In order of trust:
 *
 *   1. An explicit project_id, checked against what the user can actually see.
 *   2. A project name, matched leniently — "CIG florida" finds "CIG Florida".
 *   3. Nothing given, and the user is only on one project: use it, do not ask.
 *   4. Nothing given and several projects: ask, and hand back the list to offer.
 */
final class ProjectResolver
{
    /** A name match this strong, this far ahead of the runner-up, is safe to act on. */
    private const DECISIVE_SCORE = 60;

    private const DECISIVE_GAP = 15;

    /**
     * @param  array<string, mixed>  $args
     */
    public function resolve(ToolContext $context, array $args, string $purpose = 'this'): ProjectResolution
    {
        $workspace = $context->workspace;

        if ($workspace === null) {
            return ProjectResolution::noProjects();
        }

        /** @var Collection<int, Project> $projects */
        $projects = $workspace->accessibleProjectsFor($context->user)->orderBy('name')->get();

        if ($projects->isEmpty()) {
            return ProjectResolution::noProjects();
        }

        if (isset($args['project_id'])) {
            return $this->byId($projects, (int) $args['project_id']);
        }

        $name = isset($args['project_name']) ? trim((string) $args['project_name']) : '';

        if ($name !== '') {
            return $this->byName($projects, $name);
        }

        if ($projects->count() === 1) {
            return ProjectResolution::resolved($projects->first());
        }

        return ProjectResolution::ambiguous(
            $this->summarise($projects),
            "There are {$projects->count()} projects here, so it is not clear which one {$purpose} belongs to. "
                .'Ask the user which project they mean.',
        );
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    private function byId(Collection $projects, int $projectId): ProjectResolution
    {
        $project = $projects->firstWhere('id', $projectId);

        if ($project !== null) {
            return ProjectResolution::resolved($project);
        }

        return ProjectResolution::notFound(
            $this->summarise($projects),
            'That project does not exist or you do not have access to it. These are the projects you can use.',
        );
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    private function byName(Collection $projects, string $name): ProjectResolution
    {
        $ranked = FuzzyMatcher::rank($name, $projects, fn (Project $project) => [$project->name]);

        if ($ranked === []) {
            return ProjectResolution::notFound(
                $this->summarise($projects),
                "No project here is called \"{$name}\". These are the projects you can use.",
            );
        }

        $top = $ranked[0];
        $runnerUp = $ranked[1]['score'] ?? 0;

        $isDecisive = count($ranked) === 1
            || ($top['score'] >= self::DECISIVE_SCORE && ($top['score'] - $runnerUp) >= self::DECISIVE_GAP);

        if ($isDecisive) {
            return ProjectResolution::resolved($top['item']);
        }

        return ProjectResolution::ambiguous(
            array_map(
                fn (array $row) => [
                    'id' => $row['item']->id,
                    'name' => UntrustedText::inline($row['item']->name),
                    'match_confidence' => $row['score'],
                ],
                array_slice($ranked, 0, 5),
            ),
            "\"{$name}\" matches more than one project. Ask the user which one they mean.",
        );
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<int, array{id: int, name: string|null}>
     */
    private function summarise(Collection $projects): array
    {
        return $projects
            ->take(25)
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => UntrustedText::inline($project->name),
            ])
            ->values()
            ->all();
    }
}
