<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Http\Controllers;

use App\Modules\Meetings\Actions\CreateMeetingAction;
use App\Modules\Meetings\Actions\DeleteMeetingAction;
use App\Modules\Meetings\Actions\UpdateMeetingAction;
use App\Modules\Meetings\Http\Requests\StoreMeetingRequest;
use App\Modules\Meetings\Http\Requests\UpdateMeetingRequest;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MeetingController
{
    public function store(
        StoreMeetingRequest $request,
        Workspace $workspace,
        Project $project,
        CreateMeetingAction $action,
    ): RedirectResponse {
        $meeting = $action->handle($project, $request->user(), $request->toDTO());

        return back()->with('success', "Meeting \"{$meeting->title}\" scheduled.");
    }

    public function update(
        UpdateMeetingRequest $request,
        Workspace $workspace,
        Project $project,
        Meeting $meeting,
        UpdateMeetingAction $action,
    ): RedirectResponse {
        $meeting = $action->handle($meeting, $request->toDTO());

        return back()->with('success', "Meeting \"{$meeting->title}\" updated.");
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        Project $project,
        Meeting $meeting,
        DeleteMeetingAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('delete', $meeting), 403);

        $title = $meeting->title;

        $action->handle($meeting);

        return back()->with('success', "Meeting \"{$title}\" deleted.");
    }
}
