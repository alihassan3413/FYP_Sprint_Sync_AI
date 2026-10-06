<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Controllers;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Actions\DecideIdleTimeAction;
use App\Modules\TimeTracking\Actions\RecordActivitySignalAction;
use App\Modules\TimeTracking\Actions\ResolveTimeReviewAction;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Http\Requests\ActivitySignalRequest;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeIdlePeriod;
use App\Modules\TimeTracking\Models\TimeReview;
use App\Modules\TimeTracking\Support\TimeAccess;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Idle decisions (the person, about their own idle time), owner reviews and
 * activity signals from SprintSync Desktop.
 */
final class TimeReviewController
{
    public function __construct(private readonly TimeAccess $access) {}

    public function keepIdle(Request $request, Workspace $workspace, string $idlePeriod, DecideIdleTimeAction $action): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say what you were doing, for example "Client phone call".']);
        $period = $action->keep($this->me($request, $workspace), $this->idlePeriod($workspace, $idlePeriod), $validated['reason'], $request->user());

        return $request->wantsJson() ? response()->json(['decision' => $period->decision]) : back()->with('success', 'Idle time kept as work.');
    }

    public function dropIdle(Request $request, Workspace $workspace, string $idlePeriod, DecideIdleTimeAction $action): JsonResponse|RedirectResponse
    {
        $period = $action->drop($this->me($request, $workspace), $this->idlePeriod($workspace, $idlePeriod));

        return $request->wantsJson() ? response()->json(['decision' => $period->decision]) : back()->with('success', "Idle time won't be counted.");
    }

    public function resolve(Request $request, Workspace $workspace, string $review, ResolveTimeReviewAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('manageTeam', [TimeEntry::class, $workspace]), 403);
        $model = TimeReview::query()->where('workspace_id', $workspace->id)->where('public_id', $review)->firstOrFail();
        $validated = $request->validate([
            'decision' => ['required', Rule::in($model->kind->decisions())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $action->handle($model, $validated['decision'], $validated['note'] ?? null, $request->user());

        return back()->with('success', 'Review saved.');
    }

    public function activity(ActivitySignalRequest $request, Workspace $workspace, RecordActivitySignalAction $action): JsonResponse
    {
        $action->handle(
            $this->me($request, $workspace),
            CarbonImmutable::parse((string) $request->validated('window_start'))->utc(),
            CarbonImmutable::parse((string) $request->validated('window_end'))->utc(),
            (int) $request->validated('resets'),
            (int) $request->validated('mean_gap_ms'),
            (int) $request->validated('variation_bp'),
        );

        /* The result isn't returned: the app doesn't need to know, and nothing changes for the person. */
        return response()->json(['received' => true]);
    }

    private function me(Request $request, Workspace $workspace): Person
    {
        abort_unless($request->user()->can('track', [TimeEntry::class, $workspace]), 403);

        return $this->access->personFor($request->user(), $workspace) ?? throw TimeTrackingException::noProfile();
    }

    private function idlePeriod(Workspace $workspace, string $publicId): TimeIdlePeriod
    {
        return TimeIdlePeriod::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->firstOrFail();
    }
}
