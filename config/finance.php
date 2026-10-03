<?php

declare(strict_types=1);

return [
    /*
     * How long an approved invoice waits before it may be issued and sent, so
     * the owner can still cancel sending and fix a mistake. One value for every
     * workspace for now; a per-workspace setting can override it later.
     */
    'approval_send_delay_minutes' => (int) env('FINANCE_APPROVAL_SEND_DELAY_MINUTES', 60),
];
