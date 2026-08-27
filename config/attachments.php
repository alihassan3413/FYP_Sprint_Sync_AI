<?php

declare(strict_types=1);

return [
    'disk' => env('ATTACHMENTS_DISK', 'local'),

    /*
     * Largest single upload, in kilobytes. Enforced server-side by
     * StoreAttachmentRequest and mirrored to the browser through the shared
     * Inertia props so oversized files are rejected before they are sent.
     *
     * 10MB covers what actually gets attached to a task — screenshots, specs,
     * spreadsheets — without letting the disk fill up with video. The few
     * types that routinely exceed it are better off linked than uploaded.
     *
     * Raising this past PHP's own upload_max_filesize / post_max_size has no
     * effect: PHP rejects the request before Laravel ever validates it.
     */
    'max_kilobytes' => (int) env('ATTACHMENTS_MAX_KB', 10240),

    /*
     * Extension allowlist. Deliberately not "anything": every entry here can
     * be handed back to a browser by AttachmentController::show, so anything
     * that executes on open (exe, bat, sh, php) or that renders inline with
     * script (html, htm, svg, xhtml) stays out. Those are the file types that
     * turn an upload box into a way to attack the people who download from it.
     */
    'allowed_extensions' => [
        // Images
        'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'avif',
        // Documents
        'pdf', 'txt', 'md', 'rtf', 'doc', 'docx', 'odt',
        // Spreadsheets
        'csv', 'tsv', 'xls', 'xlsx', 'ods',
        // Presentations
        'ppt', 'pptx', 'odp',
        // Data
        'json', 'xml', 'yml', 'yaml', 'log',
        // Audio and video
        'mp3', 'wav', 'm4a', 'ogg', 'mp4', 'mov', 'webm',
        // Archives
        'zip', '7z', 'gz', 'tar',
    ],

    'max_per_comment' => (int) env('ATTACHMENTS_MAX_PER_COMMENT', 6),

    'max_per_task' => (int) env('ATTACHMENTS_MAX_PER_TASK', 10),

    'prune_unclaimed_after_hours' => (int) env('ATTACHMENTS_PRUNE_HOURS', 24),
];
