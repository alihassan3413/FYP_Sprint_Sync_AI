<?php

declare(strict_types=1);

namespace App\Modules\Projects\Exceptions;

use App\Exceptions\AppException;
use App\Modules\Projects\Data\SprintStatus;
use App\Support\Errors\ErrorCode;

final class SprintException extends AppException
{
    public static function invalidTransition(string $sprintName, SprintStatus $from, SprintStatus $to): self
    {
        return new self(
            code: ErrorCode::SPRINT_INVALID_TRANSITION,
            status: 422,
            message: "\"{$sprintName}\" is {$from->label()} and cannot be moved to {$to->label()}.",
            meta: ['from' => $from->value, 'to' => $to->value],
        );
    }

    public static function anotherSprintIsActive(string $activeSprintName): self
    {
        return new self(
            code: ErrorCode::SPRINT_ALREADY_ACTIVE,
            status: 422,
            message: "\"{$activeSprintName}\" is still running. Complete it before starting another sprint.",
            meta: ['active_sprint' => $activeSprintName],
        );
    }

    public static function isCompleted(string $sprintName): self
    {
        return new self(
            code: ErrorCode::SPRINT_IS_COMPLETED,
            status: 422,
            message: "\"{$sprintName}\" is completed. Its history cannot be changed.",
        );
    }

    public static function carryOverTargetInvalid(): self
    {
        return new self(
            code: ErrorCode::SPRINT_CARRY_OVER_TARGET_INVALID,
            status: 422,
            message: 'Unfinished work can only be carried into a planned sprint in the same project.',
        );
    }
}
