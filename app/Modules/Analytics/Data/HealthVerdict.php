<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

/**
 * How a project is doing overall, derived from the signals found against it
 * rather than from a single number. A project can be 80% complete and still be
 * in trouble if one person is carrying all of the remaining work.
 */
enum HealthVerdict: string
{
    case NoData = 'no_data';
    case Healthy = 'healthy';
    case Watch = 'watch';
    case AtRisk = 'at_risk';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::NoData => 'Nothing to judge yet',
            self::Healthy => 'Healthy',
            self::Watch => 'Worth watching',
            self::AtRisk => 'At risk',
            self::Critical => 'Needs attention now',
        };
    }

    /**
     * @param  array<int, HealthSignalData>  $signals
     */
    public static function fromSignals(array $signals, bool $hasWork): self
    {
        if (! $hasWork) {
            return self::NoData;
        }

        $critical = 0;
        $warnings = 0;

        foreach ($signals as $signal) {
            if ($signal->severity === 'critical') {
                $critical++;
            }

            if ($signal->severity === 'warning') {
                $warnings++;
            }
        }

        return match (true) {
            $critical > 0 => self::Critical,
            $warnings >= 2 => self::AtRisk,
            $warnings === 1 => self::Watch,
            default => self::Healthy,
        };
    }
}
