<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Models\User;
use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;

final class CreateMeetingAction
{
    public function handle(Project $project, User $creator, StoreMeetingData $data): Meeting
    {
        return $project->meetings()->create([
            'title' => $data->title,
            'description' => $data->description,
            'scheduled_at' => $data->scheduled_at,
            'duration_minutes' => $data->duration_minutes,
            'meeting_link' => $data->meeting_link,
            'workspace_id' => $project->workspace_id,
            'created_by' => $creator->id,
        ]);
    }
}
