<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Controllers;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Actions\AddHistoricalHoursAction;
use App\Modules\TimeTracking\Http\Requests\PreviousHoursRequest;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Support\TimeAccess;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Owner-only: totals for a period before tracking started, for the whole team at once. */
final class PreviousHoursController
{
    public function create(Request $request, Workspace $workspace, TimeAccess $access): Response
    {
        abort_unless($request->user()->can('manageTeam', [TimeEntry::class, $workspace]), 403);

        return Inertia::render('time/previous-hours', [
            'people' => $workspace->people()->orderBy('name')->get()->map(fn (Person $person) => $person->only(['public_id', 'name', 'title']))->values(),
            'projects' => TimeIndexController::projectOptions($access->projects($request->user(), $workspace)),
            'today' => CarbonImmutable::now($workspace->timezone)->toDateString(),
        ]);
    }

    public function store(PreviousHoursRequest $request, Workspace $workspace, AddHistoricalHoursAction $action): RedirectResponse
    {
        $batch = $action->handle(
            $workspace,
            $request->project(),
            $request->task(),
            $request->businessDate('period_start'),
            $request->businessDate('period_end'),
            (string) $request->validated('reason'),
            $request->boolean('billable', true),
            $request->rows(),
            $request->key(),
            $request->user(),
        );

        return to_route('workspace.time.index', ['workspace' => $workspace->slug])
            ->with('success', 'Saved previous hours for '.$batch->entries()->count().' people.');
    }
}
