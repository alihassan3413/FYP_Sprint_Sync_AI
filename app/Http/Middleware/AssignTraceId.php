<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Errors\TraceId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Initializes the per-request trace ID and propagates it to:
 *   - The application logger (every log entry gets trace_id + user_id)
 *   - The response header (X-Trace-Id) for frontend / Sentry / debugging
 *
 * Runs as the FIRST global middleware so even early-failing requests
 * have a trace ID attached to their logs.
 */
class AssignTraceId
{
    public function __construct(private TraceId $traceId) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Honor inbound trace ID from upstream (proxy / another service).
        // Otherwise the service generates a fresh ULID on first .get().
        $inbound = $request->header('X-Trace-Id');
        if (is_string($inbound) && $inbound !== '') {
            $this->traceId->set($inbound);
        }

        $id = $this->traceId->get();

        // From this point on, EVERY Log::* call in this request includes
        // trace_id and user_id automatically. This is the magic that makes
        // production debugging feasible.
        Log::shareContext([
            'trace_id' => $id,
            'user_id'  => optional($request->user())->getKey(),
        ]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Trace-Id', $id);

        return $response;
    }
}
