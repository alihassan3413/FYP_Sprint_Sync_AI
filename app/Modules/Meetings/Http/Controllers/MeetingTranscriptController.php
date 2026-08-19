<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Http\Controllers;

use App\Modules\Meetings\Actions\StoreManualTranscript;
use App\Modules\Meetings\Actions\StoreMeetingRecording;
use App\Modules\Meetings\Data\TranscriptStatus;
use App\Modules\Meetings\Http\Requests\StoreManualTranscriptRequest;
use App\Modules\Meetings\Http\Requests\StoreMeetingRecordingRequest;
use App\Modules\Meetings\Jobs\TranscribeMeetingJob;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingTranscript;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MeetingTranscriptController
{
    public function storeRecording(
        StoreMeetingRecordingRequest $request,
        Workspace $workspace,
        Project $project,
        Meeting $meeting,
        StoreMeetingRecording $action,
    ): RedirectResponse {
        $action->handle($meeting, $request->user(), $request->file('recording'));

        return back()->with('success', 'Recording uploaded. Transcription has been queued.');
    }

    public function storeManual(
        StoreManualTranscriptRequest $request,
        Workspace $workspace,
        Project $project,
        Meeting $meeting,
        StoreManualTranscript $action,
    ): RedirectResponse {
        $action->handle($meeting, $request->user(), $request->string('text')->toString());

        return back()->with('success', 'Transcript saved.');
    }

    public function retry(
        Request $request,
        Workspace $workspace,
        Project $project,
        Meeting $meeting,
    ): RedirectResponse {
        abort_unless($request->user()->can('manageTranscript', $meeting), 403);

        $transcript = MeetingTranscript::query()->where('meeting_id', $meeting->id)->firstOrFail();

        abort_unless($transcript->isRetryable(), 422);

        $transcript->update(['status' => TranscriptStatus::Queued, 'failure_reason' => null]);

        TranscribeMeetingJob::dispatch($transcript->id);

        return back()->with('success', 'Transcription has been queued again.');
    }
}
