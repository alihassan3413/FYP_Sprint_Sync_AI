<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use App\Modules\Projects\Models\Project;
use Illuminate\Support\Collection;

final class AnalyticsScope
{
    public const TEAM = 'team';

    public const PERSONAL = 'personal';

    /**
     * @param  Collection<int, Project>  $accessibleProjects
     * @param  Collection<int, int>  $teamProjectIds
     */
    public function __construct(
        public readonly Collection $accessibleProjects,
        public readonly Collection $teamProjectIds,
        public readonly ?int $personalUserId = null,
    ) {}

    /**
     * @param  Collection<int, Project>  $accessibleProjects
     */
    public static function teamWide(Collection $accessibleProjects): self
    {
        return new self($accessibleProjects, $accessibleProjects->pluck('id'), null);
    }

    public function label(): string
    {
        return $this->teamProjectIds->isEmpty() ? self::PERSONAL : self::TEAM;
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, int>
     */
    public function teamProjectIdsWithin(array $projectIds): array
    {
        return array_values(array_intersect($projectIds, $this->teamProjectIds->all()));
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, int>
     */
    public function personalProjectIdsWithin(array $projectIds): array
    {
        if ($this->personalUserId === null) {
            return [];
        }

        return array_values(array_diff($projectIds, $this->teamProjectIds->all()));
    }
}
