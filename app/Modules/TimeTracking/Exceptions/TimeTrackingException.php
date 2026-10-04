<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Exceptions;

use App\Exceptions\AppException;
use App\Support\Errors\ErrorCode;

final class TimeTrackingException extends AppException
{
    public static function timerAlreadyRunning(): self
    {
        return new self(code: ErrorCode::TIMER_ALREADY_RUNNING, status: 409, message: 'You already have a timer running. Stop it before starting another.');
    }

    public static function noActiveTimer(): self
    {
        return new self(code: ErrorCode::TIMER_NOT_RUNNING, status: 409, message: "There's no timer running.");
    }

    public static function noProfile(): self
    {
        return new self(code: ErrorCode::TIME_NO_PROFILE, status: 403, message: "Your account isn't linked to a Team profile yet, so time can't be recorded for you.");
    }

    public static function operationConflict(): self
    {
        return new self(code: ErrorCode::TIME_OPERATION_CONFLICT, status: 409, message: 'That request id was already used for a different timer action.');
    }

    public static function invalid(string $message): self
    {
        return new self(code: ErrorCode::TIME_INVALID, status: 422, message: $message);
    }
}
