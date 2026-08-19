<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use Illuminate\Support\Str;

/**
 * Scores how well a phrase a person typed matches a record's text, on 0-100.
 *
 * People do not quote task titles back exactly. They say "the UI UX task" for
 * "UI/UX modification", "checkout bug" for "Fix the checkout bug on mobile", or
 * they misspell it. The rules below are ordered from most to least certain, and
 * the best rule that fires wins:
 *
 *   100  identical once normalised
 *   85-99  the whole phrase appears inside the text (longer overlap scores higher)
 *   60-90  every word of the phrase appears somewhere in the text, in any order
 *   25-75  some words match, scaled by how many, with credit for near-misses
 *
 * Deliberately generous at the bottom end: it is better to show a person four
 * candidates and let them pick than to tell them nothing was found.
 */
final class FuzzyMatcher
{
    /** Anything below this is noise and never shown. */
    public const FLOOR = 25;

    /** At or above this, a single match can be acted on without asking. */
    public const CONFIDENT = 85;

    public static function score(string $needle, ?string $haystack): int
    {
        $needle = self::normalise($needle);
        $haystack = self::normalise((string) $haystack);

        if ($needle === '' || $haystack === '') {
            return 0;
        }

        if ($needle === $haystack) {
            return 100;
        }

        if (str_contains($haystack, $needle)) {
            /* A phrase covering most of the title is a stronger signal than one word of many. */
            $coverage = mb_strlen($needle) / max(1, mb_strlen($haystack));

            return (int) round(85 + (14 * min(1.0, $coverage)));
        }

        $needleTokens = self::tokens($needle);
        $haystackTokens = self::tokens($haystack);

        if ($needleTokens === [] || $haystackTokens === []) {
            return 0;
        }

        $matched = 0.0;

        foreach ($needleTokens as $token) {
            $matched += self::bestTokenScore($token, $haystackTokens);
        }

        $ratio = $matched / count($needleTokens);

        if ($ratio >= 1.0) {
            /* Every word landed: score by how much of the title those words cover. */
            $coverage = count($needleTokens) / max(1, count($haystackTokens));

            return (int) round(60 + (30 * min(1.0, $coverage)));
        }

        $partial = (int) round($ratio * 75);

        similar_text($needle, $haystack, $percent);

        return max($partial, (int) round($percent * 0.6));
    }

    /**
     * Best score for one word of the phrase against any word of the text:
     * 1.0 exact, 0.9 stem-ish prefix, 0.75 typo-close, 0.5 contained.
     */
    private static function bestTokenScore(string $token, array $haystackTokens): float
    {
        $best = 0.0;

        foreach ($haystackTokens as $candidate) {
            if ($token === $candidate) {
                return 1.0;
            }

            $score = 0.0;

            if (str_starts_with($candidate, $token) || str_starts_with($token, $candidate)) {
                $score = 0.9;
            } elseif (str_contains($candidate, $token) || str_contains($token, $candidate)) {
                $score = 0.5;
            } elseif (self::isTypoOf($token, $candidate)) {
                $score = 0.75;
            }

            $best = max($best, $score);
        }

        return $best;
    }

    /**
     * Typo tolerance scaled to word length. Short words are not forgiven: "rana"
     * and "ada" are two edits apart but are obviously different people.
     */
    private static function isTypoOf(string $token, string $candidate): bool
    {
        $allowed = match (true) {
            mb_strlen($token) >= 8 => 2,
            mb_strlen($token) >= 5 => 1,
            default => 0,
        };

        if ($allowed === 0 || abs(mb_strlen($token) - mb_strlen($candidate)) > $allowed) {
            return false;
        }

        return levenshtein($token, $candidate) <= $allowed;
    }

    /**
     * Ranks candidates by score, keeping only what clears the floor.
     *
     * @template T
     *
     * @param  iterable<T>  $candidates
     * @param  callable(T): array<int, string|null>  $textsFor  the fields to match against, best first
     * @return array<int, array{item: T, score: int}>
     */
    public static function rank(string $needle, iterable $candidates, callable $textsFor, int $floor = self::FLOOR): array
    {
        $ranked = [];

        foreach ($candidates as $candidate) {
            $best = 0;

            foreach ($textsFor($candidate) as $index => $text) {
                /* Later fields (a description, say) count for less than the title. */
                $weight = $index === 0 ? 1.0 : 0.7;
                $best = max($best, (int) round(self::score($needle, $text) * $weight));
            }

            if ($best >= $floor) {
                $ranked[] = ['item' => $candidate, 'score' => $best];
            }
        }

        usort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $ranked;
    }

    /**
     * Lower case, punctuation to spaces, whitespace collapsed. "UI/UX" and
     * "ui ux" have to land on the same string for any of this to work.
     */
    public static function normalise(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @return array<int, string>
     */
    private static function tokens(string $normalised): array
    {
        return array_values(array_filter(
            explode(' ', $normalised),
            fn (string $token) => $token !== '',
        ));
    }
}
