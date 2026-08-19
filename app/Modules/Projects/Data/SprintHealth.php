<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

/**
 * A verdict on whether a running sprint will land, derived from how much of the
 * scope is done against how much of the time is gone.
 */
enum SprintHealth: string
{
    case NotStarted = 'not_started';
    case Empty = 'empty';
    case OnTrack = 'on_track';
    case AtRisk = 'at_risk';
    case OffTrack = 'off_track';
    case Overdue = 'overdue';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::Empty => 'No work planned',
            self::OnTrack => 'On track',
            self::AtRisk => 'At risk',
            self::OffTrack => 'Off track',
            self::Overdue => 'Overdue',
            self::Done => 'Completed',
        };
    }

    /**
     * How far completion may lag the calendar before the sprint stops being healthy.
     * Expressed in percentage points of scope.
     */
    public const AT_RISK_GAP = 10;

    public const OFF_TRACK_GAP = 25;

    public function isTrouble(): bool
    {
        return in_array($this, [self::AtRisk, self::OffTrack, self::Overdue], true);
    }
}
