<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Support;

final class TranscriptionConfidence
{
    /**
     * Whisper reports per-segment `avg_logprob` (log probability, <= 0) and
     * `no_speech_prob`. Both are folded into a single 0-100 score so the rest
     * of the app never has to know the provider's units.
     *
     * @param  array<int, array<string, mixed>>|null  $segments
     */
    public static function fromSegments(?array $segments): ?int
    {
        if ($segments === null || $segments === []) {
            return null;
        }

        $scores = [];

        foreach ($segments as $segment) {
            if (! is_array($segment) || ! isset($segment['avg_logprob'])) {
                continue;
            }

            $probability = exp((float) $segment['avg_logprob']);
            $speech = 1.0 - (float) ($segment['no_speech_prob'] ?? 0.0);

            $scores[] = max(0.0, min(1.0, $probability * $speech));
        }

        if ($scores === []) {
            return null;
        }

        return (int) round((array_sum($scores) / count($scores)) * 100);
    }
}
