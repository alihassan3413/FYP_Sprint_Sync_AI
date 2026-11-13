<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Projects\Models\ProjectAccessRequest;

/**
 * Approving lets the person add tasks to that one board; declining only
 * closes the request. A request that was already decided is left as it is,
 * so a double click never does anything twice.
 */
final class DecideBoardAccessRequestAction
{
    public function __construct(private readonly JoinSharedBoardAction $join) {}

    public function handle(ProjectAccessRequest $request, bool $approve, User $actor): ProjectAccessRequest
    {
        $decided = ProjectAccessRequest::query()->whereKey($request->getKey())->where('status', 'pending')->update([
            'status' => $approve ? 'approved' : 'declined',
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'updated_at' => now(),
        ]);

        if ($decided === 1 && $approve) {
            $this->join->handle($request->project, $request->user, $actor);
        }

        return $request->refresh();
    }
}
