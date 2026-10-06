<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

/**
 * Decides whether a window of activity looks machine-made, from the only
 * signal SprintSync Desktop sends: summary numbers about when the computer's
 * idle clock reset (how many resets, the average gap between them, and how
 * regular those gaps were). No keys, mouse positions, apps or sites.
 *
 * People are irregular: typing produces bursts of resets a second apart,
 * then pauses of varying length. Keep-awake tools ("mouse jigglers") reset
 * the clock on a fixed beat. So the only pattern flagged is many resets at
 * an almost perfectly even, sparse interval for a long window. Reading,
 * calls and meetings produce few or no resets and are never flagged.
 *
 * The thresholds live here on the server, not in the app. The result is
 * "review recommended", never a verdict.
 */
final class ActivityPattern
{
    /** At least this many resets in the window (e.g. every 30s for 10+ minutes). */
    public const MIN_RESETS = 20;

    /** Gaps shorter than this are human bursts (typing). */
    public const MIN_MEAN_GAP_MS = 5_000;

    public const MAX_MEAN_GAP_MS = 600_000;

    /** Coefficient of variation of the gaps, in basis points: 300 = 3%. Humans are far above this. */
    public const MAX_VARIATION_BP = 300;

    /**
     * @return array{summary: string, confidence: string}|null null when nothing unusual
     */
    public static function evaluate(int $resets, int $meanGapMs, int $variationBasisPoints): ?array
    {
        if ($resets < self::MIN_RESETS || $meanGapMs < self::MIN_MEAN_GAP_MS || $meanGapMs > self::MAX_MEAN_GAP_MS) {
            return null;
        }

        if ($variationBasisPoints > self::MAX_VARIATION_BP) {
            return null;
        }

        return [
            'summary' => 'Activity appears unusually repetitive. Review recommended.',
            'confidence' => $variationBasisPoints <= 100 && $resets >= 2 * self::MIN_RESETS ? 'high' : 'medium',
        ];
    }
}
