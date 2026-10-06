<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Controllers;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Actions\EndLunchAction;
use App\Modules\TimeTracking\Actions\IdlePauseTimerAction;
use App\Modules\TimeTracking\Actions\PauseTimerAction;
use App\Modules\TimeTracking\Actions\ResumeTimerAction;
use App\Modules\TimeTracking\Actions\StartLunchAction;
use App\Modules\TimeTracking\Actions\StartTimerAction;
use App\Modules\TimeTracking\Actions\StopTimerAction;
use App\Modules\TimeTracking\Data\TimerData;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Http\Requests\IdlePauseRequest;
use App\Modules\TimeTracking\Http\Requests\StartTimerRequest;
use App\Modules\TimeTracking\Http\Requests\TimerOperationRequest;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\TimeAccess;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person's own timer. JSON for API clients (SprintSync
 * Desktop, signed in with its device token), a redirect with a message for
 * the web page; the same actions underneath. A person always acts on their own timer, never on someone
 * else's.
 */
final class TimerController
{
    public function __construct(private readonly TimeAccess $access) {}

    public function current(Request $request, Workspace $workspace): JsonResponse
    {
        abort_unless($request->user()->can('track', [TimeEntry::class, $workspace]), 403);
        $person = $this->access->personFor($request->user(), $workspace);
        $session = $person === null ? null : TimeSession::query()->where('active_person_id', $person->id)->first();

        return response()->json([
            'active' => $session !== null,
            'timer' => $session === null ? null : TimerData::fromSession($session, now()),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function start(StartTimerRequest $request, Workspace $workspace, StartTimerAction $action): JsonResponse|RedirectResponse
    {
        $session = $action->handle(
            $this->me($request, $workspace),
            $request->project(),
            $request->task(),
            $request->boolean('billable', true),
            $request->validated('note'),
            $request->key('operation_key'),
            $request->user(),
        );

        return $this->respond($request, $session, 'Timer started.');
    }

    public function pause(TimerOperationRequest $request, Workspace $workspace, PauseTimerAction $action): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $action->handle($this->me($request, $workspace), $request->key('operation_key'), $request->validated('session'), $request->occurredAt()), 'Timer paused.');
    }

    public function resume(TimerOperationRequest $request, Workspace $workspace, ResumeTimerAction $action): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $action->handle($this->me($request, $workspace), $request->key('operation_key'), $request->validated('session'), $request->occurredAt()), 'Timer resumed.');
    }

    public function stop(TimerOperationRequest $request, Workspace $workspace, StopTimerAction $action): JsonResponse|RedirectResponse
    {
        $session = $action->handle($this->me($request, $workspace), $request->key('operation_key'), $request->user(), $request->validated('session'), $request->occurredAt());

        return $this->respond($request, $session, 'Timer stopped. Your time is saved.');
    }

    public function lunchStart(TimerOperationRequest $request, Workspace $workspace, StartLunchAction $action): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $action->handle($this->me($request, $workspace), $request->key('operation_key'), $request->validated('session'), $request->occurredAt()), 'Lunch started. Enjoy your break.');
    }

    public function lunchEnd(TimerOperationRequest $request, Workspace $workspace, EndLunchAction $action): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $action->handle($this->me($request, $workspace), $request->key('operation_key'), $request->validated('session'), $request->occurredAt()), 'Lunch ended. The timer is running again.');
    }

    /** SprintSync Desktop only: the automatic pause after inactivity. */
    public function idlePause(IdlePauseRequest $request, Workspace $workspace, IdlePauseTimerAction $action): JsonResponse|RedirectResponse
    {
        return $this->respond($request, $action->handle($this->me($request, $workspace), $request->key('operation_key'), (string) $request->validated('session'), $request->idleSince(), $request->detectedAt()), 'Timer paused after inactivity.');
    }

    private function me(Request $request, Workspace $workspace): Person
    {
        return $this->access->personFor($request->user(), $workspace) ?? throw TimeTrackingException::noProfile();
    }

    private function respond(Request $request, TimeSession $session, string $message): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['timer' => TimerData::fromSession($session, now())]);
        }

        return back()->with('success', $message);
    }
}
