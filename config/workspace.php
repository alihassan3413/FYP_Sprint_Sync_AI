<?php

declare(strict_types=1);

return [
    'invitation_ttl_days' => (int) env('WORKSPACE_INVITATION_TTL_DAYS', 7),

    'max_per_owner' => (int) env('WORKSPACE_MAX_PER_OWNER', 10),

    'dashboard_activity_limit' => 15,

    'dashboard_meeting_limit' => 5,

    'seat_limit' => (int) env('WORKSPACE_SEAT_LIMIT', 10),
];
