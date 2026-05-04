<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Exceptions\AppException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;


class ErrorResponseBuilder
{
    public function __construct(private TraceId $traceId) {}

    public function build(Throwable $e, Request $request): ?Response
    {
        if ($e instanceof ValidationException) {
            return null;
        }

        $payload = $this->normalize($e);

        if ($this->wantsJson($request)) {
            return $this->renderJson($payload);
        }

        if ($this->isInertiaRequest($request) && $this->isToastable($payload)) {
            return $this->renderInertiaFlash($payload);
        }

        return null;
    }


    private function normalize(Throwable $e): ErrorPayload
    {
        $traceId = $this->traceId->get();

        if ($e instanceof AppException) {
            return new ErrorPayload(
                code:    $e->getErrorCode(),
                message: $e->getMessage(),
                status:  $e->getStatusCode(),
                meta:    $e->getMeta(),
                traceId: $traceId,
            );
        }

        return match (true) {
            $e instanceof AuthenticationException => new ErrorPayload(
                code:    ErrorCode::AUTH_UNAUTHENTICATED,
                message: 'Please sign in to continue.',
                status:  401,
                traceId: $traceId,
            ),

            $e instanceof AuthorizationException => new ErrorPayload(
                code:    ErrorCode::AUTH_FORBIDDEN,
                message: 'You are not allowed to do that.',
                status:  403,
                traceId: $traceId,
            ),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => new ErrorPayload(
                code:    ErrorCode::NOT_FOUND,
                message: 'The requested resource was not found.',
                status:  404,
                traceId: $traceId,
            ),

            $e instanceof TooManyRequestsHttpException => new ErrorPayload(
                code:    ErrorCode::RATE_LIMITED,
                message: 'Too many requests. Please slow down.',
                status:  429,
                traceId: $traceId,
            ),

            $e instanceof HttpExceptionInterface => new ErrorPayload(
                code:    ErrorCode::BAD_REQUEST,
                message: $e->getMessage() ?: 'An error occurred.',
                status:  $e->getStatusCode(),
                traceId: $traceId,
            ),

            default => new ErrorPayload(
                code:    ErrorCode::INTERNAL_ERROR,
                message: 'Something went wrong on our end. Please try again.',
                status:  500,
                traceId: $traceId,
            ),
        };
    }


    private function renderJson(ErrorPayload $payload): JsonResponse
    {
        return new JsonResponse($payload->toArray(), $payload->status);
    }

    private function renderInertiaFlash(ErrorPayload $payload): Response
    {
        return back()
            ->withInput()
            ->with('error', [
                'code'    => $payload->code,
                'message' => $payload->message,
                'meta'    => array_merge($payload->meta, ['trace_id' => $payload->traceId]),
            ]);
    }

    private function isToastable(ErrorPayload $payload): bool
    {
        // 401 redirects to login; not a toast.
        if ($payload->status === 401) {
            return false;
        }

        return $payload->status >= 400
            && $payload->status < 500
            && $payload->status !== 404;
    }

    private function wantsJson(Request $request): bool
    {
        if ($this->isInertiaRequest($request)) {
            return false;
        }

        return $request->is('api/*')
            || $request->header('Accept') === 'application/json';
    }

    private function isInertiaRequest(Request $request): bool
    {
        return $request->header('X-Inertia') === 'true';
    }
}
