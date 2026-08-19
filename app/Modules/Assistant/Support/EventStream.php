<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EventStream
{
    public static function respond(Closure $callback): StreamedResponse
    {
        return new StreamedResponse(
            function () use ($callback) {
                /*
                 * Draining the buffers is what makes the stream arrive chunk by chunk
                 * behind PHP-FPM, but under test it would close the buffer the test
                 * harness is capturing with.
                 */
                if (! app()->runningUnitTests()) {
                    while (ob_get_level() > 0) {
                        ob_end_flush();
                    }
                }

                ignore_user_abort(true);
                set_time_limit((int) config('assistant.stream_timeout', 180));

                $callback(new self);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function emit(array $event): void
    {
        echo 'data: '.json_encode($event)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    public function aborted(): bool
    {
        return connection_aborted() === 1;
    }
}
