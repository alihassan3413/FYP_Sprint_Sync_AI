<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Support\FuzzyMatcher;
use PHPUnit\Framework\TestCase;

final class FuzzyMatcherTest extends TestCase
{
    public function test_an_identical_phrase_scores_full_marks(): void
    {
        $this->assertSame(100, FuzzyMatcher::score('UI/UX modification', 'ui ux modification'));
    }

    public function test_punctuation_and_case_are_ignored(): void
    {
        $this->assertGreaterThanOrEqual(FuzzyMatcher::CONFIDENT, FuzzyMatcher::score('ui ux', 'UI/UX modification'));
    }

    public function test_words_in_any_order_still_match(): void
    {
        $this->assertGreaterThanOrEqual(60, FuzzyMatcher::score('modification ux', 'UI/UX modification'));
    }

    public function test_a_partial_phrase_clears_the_floor(): void
    {
        $score = FuzzyMatcher::score('checkout bug', 'Fix the checkout bug on mobile Safari');

        $this->assertGreaterThanOrEqual(FuzzyMatcher::FLOOR, $score);
        $this->assertLessThan(100, $score);
    }

    public function test_a_small_typo_still_matches(): void
    {
        $this->assertGreaterThanOrEqual(FuzzyMatcher::FLOOR, FuzzyMatcher::score('dashbord', 'Dashboard redesign'));
    }

    public function test_a_plural_or_stem_still_matches(): void
    {
        $this->assertGreaterThanOrEqual(FuzzyMatcher::FLOOR, FuzzyMatcher::score('notification', 'Notifications panel'));
    }

    public function test_typo_tolerance_does_not_stretch_to_short_words(): void
    {
        /* "rana" and "ada" are two edits apart but are plainly different people. */
        $this->assertLessThan(FuzzyMatcher::FLOOR, FuzzyMatcher::score('rana', 'Ada Owner'));
        $this->assertGreaterThan(FuzzyMatcher::CONFIDENT, FuzzyMatcher::score('rana', 'Rana Dev'));
    }

    public function test_a_stray_common_word_does_not_make_a_match(): void
    {
        $this->assertLessThanOrEqual(FuzzyMatcher::FLOOR, FuzzyMatcher::score('shipped to production', 'To Do'));
    }

    public function test_unrelated_text_scores_below_the_floor(): void
    {
        $this->assertLessThan(FuzzyMatcher::FLOOR, FuzzyMatcher::score('payment gateway', 'Write onboarding copy'));
    }

    public function test_empty_input_never_matches(): void
    {
        $this->assertSame(0, FuzzyMatcher::score('', 'Anything'));
        $this->assertSame(0, FuzzyMatcher::score('Anything', null));
    }

    public function test_ranking_orders_by_score_and_drops_noise(): void
    {
        $candidates = [
            ['title' => 'Write onboarding copy'],
            ['title' => 'UI/UX modification'],
            ['title' => 'UI/UX modification for the settings page'],
        ];

        $ranked = FuzzyMatcher::rank('ui ux modification', $candidates, fn (array $row) => [$row['title']]);

        $this->assertCount(2, $ranked);
        $this->assertSame('UI/UX modification', $ranked[0]['item']['title']);
        $this->assertGreaterThanOrEqual($ranked[1]['score'], $ranked[0]['score']);
    }

    public function test_a_secondary_field_counts_for_less_than_the_title(): void
    {
        $onTitle = FuzzyMatcher::rank('payment', [['t' => 'Payment retries', 'd' => 'unrelated']], fn (array $r) => [$r['t'], $r['d']]);
        $onDescription = FuzzyMatcher::rank('payment', [['t' => 'Retries', 'd' => 'Payment retries']], fn (array $r) => [$r['t'], $r['d']]);

        $this->assertGreaterThan($onDescription[0]['score'], $onTitle[0]['score']);
    }
}
