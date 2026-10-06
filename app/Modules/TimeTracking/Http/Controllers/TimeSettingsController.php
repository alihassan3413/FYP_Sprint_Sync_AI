<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Controllers;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Actions\ManageTimeOffAction;
use App\Modules\TimeTracking\Actions\SaveTimePolicyAction;
use App\Modules\TimeTracking\Actions\SaveWorkScheduleAction;
use App\Modules\TimeTracking\Data\LeaveType;
use App\Modules\TimeTracking\Http\Requests\TimeOffRequest;
use App\Modules\TimeTracking\Http\Requests\TimePolicyRequest;
use App\Modules\TimeTracking\Http\Requests\WorkScheduleRequest;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeHoliday;
use App\Modules\TimeTracking\Models\TimeLeave;
use App\Modules\TimeTracking\Models\TimePolicy;
use App\Modules\TimeTracking\Models\WorkSchedule;
use App\Modules\TimeTracking\Support\HoursInput;
use App\Modules\TimeTracking\Support\TimePolicies;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner-only Time settings: the time policy (saved as a new version each
 * time), expected schedules, holidays and leave. Plain settings, not an HR
 * system: everything is optional and off by default.
 */
final class TimeSettingsController
{
    public function edit(Request $request, Workspace $workspace, TimePolicies $policies): Response
    {
        abort_unless($request->user()->can('manageTeam', [TimeEntry::class, $workspace]), 403);

        $people = $workspace->people()->orderBy('name')->get();
        $policy = $policies->at($workspace->id);
        $schedule = fn (?Person $person) => WorkSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->where('person_id', $person?->id)
            ->orderByDesc('effective_from')->orderByDesc('id')->first()
            ?->only(['days', 'start_time', 'end_time', 'timezone']);

        return Inertia::render('time/settings', [
            'policy' => [
                ...$policy->toArray(),
                'idle_minutes' => $policy->idleSeconds === null ? 5 : intdiv($policy->idleSeconds, 60),
                'lunch_minutes' => $policy->lunchAllowanceSeconds === null ? 60 : intdiv($policy->lunchAllowanceSeconds, 60),
                'weekly_target_hours' => $policy->weeklyTargetSeconds === null ? '' : rtrim(rtrim(sprintf('%d.%02d', intdiv($policy->weeklyTargetSeconds, 3600), intdiv($policy->weeklyTargetSeconds % 3600, 36)), '0'), '.'),
                'effective_from' => $policy->effectiveFrom?->toIso8601String(),
            ],
            'history' => TimePolicy::query()->where('workspace_id', $workspace->id)->with('creator:id,name')->orderByDesc('effective_from')->orderByDesc('id')->limit(6)->get()
                ->map(fn (TimePolicy $version) => ['effective_from' => $version->effective_from->toIso8601String(), 'by' => $version->creator?->name])->values(),
            'workspaceSchedule' => $schedule(null),
            'people' => $people->map(fn (Person $person) => [
                'public_id' => $person->public_id,
                'name' => $person->name,
                'schedule' => $schedule($person),
            ])->values(),
            'holidays' => TimeHoliday::query()->active()->where('workspace_id', $workspace->id)->with('people:id,public_id,name')->orderByDesc('date')->limit(30)->get()
                ->map(fn (TimeHoliday $holiday) => [
                    'public_id' => $holiday->public_id,
                    'date' => $holiday->date->toDateString(),
                    'name' => $holiday->name,
                    'credit_seconds' => $holiday->credit_seconds,
                    'people' => $holiday->people->pluck('name')->values(),
                ])->values(),
            'leaves' => TimeLeave::query()->active()->where('workspace_id', $workspace->id)->with('person:id,name')->orderByDesc('start_date')->limit(30)->get()
                ->map(fn (TimeLeave $leave) => [
                    'public_id' => $leave->public_id,
                    'person' => $leave->person->name,
                    'start_date' => $leave->start_date->toDateString(),
                    'end_date' => $leave->end_date->toDateString(),
                    'type' => $leave->type->value,
                    'credit_seconds_per_day' => $leave->credit_seconds_per_day,
                    'note' => $leave->note,
                ])->values(),
            'timezone' => $workspace->timezone,
            'today' => CarbonImmutable::now($workspace->timezone)->toDateString(),
        ]);
    }

    public function updatePolicy(TimePolicyRequest $request, Workspace $workspace, SaveTimePolicyAction $action): RedirectResponse
    {
        $action->handle($workspace, $request->policy(), $request->user());

        return back()->with('success', 'Time policy saved. It applies from now on; earlier time keeps the rules it was recorded under.');
    }

    public function saveSchedule(WorkScheduleRequest $request, Workspace $workspace, SaveWorkScheduleAction $action): RedirectResponse
    {
        $action->handle(
            $workspace,
            $request->targetPerson(),
            (array) $request->validated('days'),
            $request->validated('start_time'),
            $request->validated('end_time'),
            $request->validated('timezone'),
            $request->user(),
        );

        return back()->with('success', 'Schedule saved.');
    }

    public function storeTimeOff(TimeOffRequest $request, Workspace $workspace, ManageTimeOffAction $action): RedirectResponse
    {
        $seconds = (int) HoursInput::toSeconds((string) $request->validated('hours'));

        if ($request->validated('kind') === 'holiday') {
            $action->addHoliday($workspace, $request->businessDate('start_date'), (string) $request->validated('name'), $seconds, $request->people(), $request->user());

            return back()->with('success', 'Holiday added.');
        }

        $action->addLeave(
            $request->targetPerson(),
            $request->businessDate('start_date'),
            $request->businessDate('end_date'),
            LeaveType::from((string) $request->validated('type')),
            $seconds,
            $request->validated('note'),
            $request->key(),
            $request->user(),
        );

        return back()->with('success', 'Leave added.');
    }

    public function destroyHoliday(Request $request, Workspace $workspace, string $holiday, ManageTimeOffAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('manageTeam', [TimeEntry::class, $workspace]), 403);
        $action->removeHoliday(TimeHoliday::query()->where('workspace_id', $workspace->id)->where('public_id', $holiday)->firstOrFail(), $request->user());

        return back()->with('success', 'Holiday removed.');
    }

    public function destroyLeave(Request $request, Workspace $workspace, string $leave, ManageTimeOffAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('manageTeam', [TimeEntry::class, $workspace]), 403);
        $action->removeLeave(TimeLeave::query()->where('workspace_id', $workspace->id)->where('public_id', $leave)->firstOrFail(), $request->user());

        return back()->with('success', 'Leave removed.');
    }
}
