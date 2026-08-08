<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Collection;

final class ResolveTaskRecipients
{
    /**
     * @return Collection<int, User>
     */
    public function handle(Task $task, User $actor): Collection
    {
        $assignee = $task->assignee;

        if ($assignee === null || $assignee->id === $actor->id) {
            return collect();
        }

        return collect([$assignee]);
    }
}
