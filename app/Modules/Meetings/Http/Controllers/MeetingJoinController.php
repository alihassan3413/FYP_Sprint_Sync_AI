<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Http\Controllers;

use App\Modules\Meetings\Models\Meeting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class MeetingJoinController
{
    public function __invoke(Request $request, string $token): Response
    {
        $meeting = Meeting::query()
            ->with('project:id,name')
            ->where('join_token', $token)
            ->firstOrFail();

        $user = Auth::user();
        $isInternal = false;

        if ($user !== null) {
            $isInternal = $meeting->hasParticipant($user) || $user->can('update', $meeting);

            abort_unless($isInternal, 403);
        }

        return Inertia::render('meetings/Join', [
            'meeting' => [
                'title' => $meeting->title,
                'agenda' => $meeting->description,
                'scheduled_at' => $meeting->scheduled_at->toIso8601String(),
                'duration_minutes' => $meeting->duration_minutes,
                'has_started' => $meeting->scheduled_at->isPast(),
                'project_name' => $isInternal ? $meeting->project->name : null,
            ],
            'joinUrl' => $meeting->hasValidJoinLink() ? $meeting->meeting_link : null,
            'isInternal' => $isInternal,
        ]);
    }
}
