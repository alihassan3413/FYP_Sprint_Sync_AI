<?php

use App\Exceptions\AppException;
use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Errors\ErrorResponseBuilder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(AssignTraceId::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        $exceptions->render(function (Throwable $e, $request) {
            return app()->make(ErrorResponseBuilder::class)->build($e, $request);
        });

        $exceptions->reportable(function (AppException $e) {
            if (! $e->shouldReport()) {
                return false; // suppress logging for this exception
            }
        });

        $exceptions->dontReport([
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Auth\AuthenticationException::class,
        ]);

    })->create();
