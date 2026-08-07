<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Modules\Meetings\Models\Meeting;

final class DeleteMeetingAction
{
    public function handle(Meeting $meeting): void
    {
        $meeting->delete();
    }
}
