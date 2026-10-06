<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Controllers;

use App\Modules\TimeTracking\Actions\AddManualTimeAction;
use App\Modules\TimeTracking\Actions\AddTimeAdjustmentAction;
use App\Modules\TimeTracking\Actions\VoidTimeEntryAction;
use App\Modules\TimeTracking\Data\TimeEntryData;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Http\Requests\AdjustTimeRequest;
use App\Modules\TimeTracking\Http\Requests\ManualTimeRequest;
use App\Modules\TimeTracking\Http\Requests\VoidTimeEntryRequest;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Support\TimeAccess;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class TimeEntryController
{
    public function store(ManualTimeRequest $request, Workspace $workspace, AddManualTimeAction $action, TimeAccess $access): JsonResponse|RedirectResponse
    {
        $person = $request->targetPerson() ?? $access->personFor($request->user(), $workspace) ?? throw TimeTrackingException::noProfile();

        $entry = $action->handle(
            $person,
            $request->project(),
            $request->task(),
            $request->businessDate('date'),
            $request->seconds(),
            $request->boolean('billable', true),
            $request->validated('note'),
            $request->key(),
            $request->user(),
        );

        return $request->wantsJson()
            ? response()->json(['entry' => TimeEntryData::fromModel($entry)], 201)
            : back()->with('success', 'Time added.');
    }

    public function adjust(AdjustTimeRequest $request, Workspace $workspace, TimeEntry $timeEntry, AddTimeAdjustmentAction $action): RedirectResponse
    {
        $action->forEntry($timeEntry, $request->seconds(), (string) $request->validated('reason'), $request->key(), $request->user());

        return back()->with('success', 'Correction added. The original time is kept.');
    }

    public function void(VoidTimeEntryRequest $request, Workspace $workspace, TimeEntry $timeEntry, VoidTimeEntryAction $action): RedirectResponse
    {
        $action->handle($timeEntry, (string) $request->validated('reason'), $request->user());

        return back()->with('success', 'Time entry voided.');
    }
}
