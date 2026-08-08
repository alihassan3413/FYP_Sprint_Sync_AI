<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;

final class CreateBoardColumnAction
{
    public function handle(Project $project, string $name): BoardColumn
    {
        $position = (int) $project->boardColumns()->max('position') + 1;

        return $project->boardColumns()->create([
            'name' => $name,
            'position' => $position,
            'is_default' => false,
            'is_done' => false,
        ]);
    }
}
