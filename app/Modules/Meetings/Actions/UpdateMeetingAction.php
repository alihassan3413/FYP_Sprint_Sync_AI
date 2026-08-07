<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;

final class UpdateMeetingAction
{
    public function handle(Meeting $meeting, StoreMeetingData $data): Meeting
    {
        $meeting->update([
            'title' => $data->title,
            'description' => $data->description,
            'scheduled_at' => $data->scheduled_at,
            'duration_minutes' => $data->duration_minutes,
            'meeting_link' => $data->meeting_link,
        ]);

        return $meeting->refresh();
    }
}
