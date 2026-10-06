<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Modules\Billing\Data\AdjustmentKind;
use App\Modules\Billing\Data\AdjustmentType;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\Money;
use App\Modules\Billing\Support\InvoiceCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InvoiceCalculatorTest extends TestCase
{
    private InvoiceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new InvoiceCalculator;
    }

    public function test_the_rocketflood_dev_and_office_invoice_totals_5059_20(): void
    {
        $fixed = fn (int $cents) => ['quantity' => 100, 'unit_price' => $cents];

        $totals = $this->calculator->calculate(
            Currency::USD,
            [
                $fixed(200_000), // Ali Hassan — Developer
                $fixed(120_000), // Aamir Sattar — UI/UX
                $fixed(30_000),  // Azhar Hussain
                $fixed(110_000), // Office Rent
                $fixed(18_000),  // Office Boy
                $fixed(18_000),  // Guard
            ],
            [['kind' => AdjustmentKind::Fee, 'type' => AdjustmentType::Percentage, 'value' => 200]], // Deel fee 2%
        );

        $this->assertSame(496_000, $totals->subtotal->minor);
        $this->assertSame(9_920, $totals->adjustments[0]->minor);
        $this->assertSame(505_920, $totals->total->minor);
        $this->assertSame(Currency::USD, $totals->total->currency);
    }

    public function test_160_hours_at_5_55_is_888_00(): void
    {
        $totals = $this->calculator->calculate(Currency::USD, [['quantity' => 16_000, 'unit_price' => 555]]);

        $this->assertSame(88_800, $totals->lines[0]->minor);
        $this->assertSame(88_800, $totals->total->minor);
    }

    public function test_fractional_hours_round_half_up_to_the_cent(): void
    {
        $totals = $this->calculator->calculate(Currency::USD, [
            ['quantity' => 16_850, 'unit_price' => 496], // 168.5h × $4.96 = $835.76 exactly
            ['quantity' => 16_850, 'unit_price' => 555], // 168.5h × $5.55 = $935.175 → $935.18
        ]);

        $this->assertSame(83_576, $totals->lines[0]->minor);
        $this->assertSame(93_518, $totals->lines[1]->minor);
        $this->assertSame(177_094, $totals->subtotal->minor);
    }

    public function test_the_rocketflood_csr_august_invoice(): void
    {
        $hourly = fn (int $hours, int $rate) => ['quantity' => $hours * 100, 'unit_price' => $rate];

        $totals = $this->calculator->calculate(Currency::USD, [
            $hourly(160, 555), // Muhammad Usman Ghani
            $hourly(172, 455), // Abdul Wahab
            $hourly(168, 496), // Faheem
            $hourly(152, 496), // Abdul Rehman
            $hourly(160, 555), // Muhammad Faizan
        ]);

        $this->assertSame([88_800, 78_260, 83_328, 75_392, 88_800], array_map(fn (Money $m) => $m->minor, $totals->lines));
        $this->assertSame(414_580, $totals->total->minor);
    }

    public function test_percentage_adjustments_apply_to_the_subtotal_and_never_compound(): void
    {
        $totals = $this->calculator->calculate(
            Currency::USD,
            [['quantity' => 100, 'unit_price' => 100_000]],
            [
                ['kind' => AdjustmentKind::Fee, 'type' => AdjustmentType::Percentage, 'value' => 200],
                ['kind' => AdjustmentKind::Tax, 'type' => AdjustmentType::Percentage, 'value' => 500],
            ],
        );

        $this->assertSame(2_000, $totals->adjustments[0]->minor);
        $this->assertSame(5_000, $totals->adjustments[1]->minor);
        $this->assertSame(107_000, $totals->total->minor);
    }

    public function test_discounts_subtract_and_fixed_fees_add(): void
    {
        $totals = $this->calculator->calculate(
            Currency::PKR,
            [['quantity' => 100, 'unit_price' => 4_000_000]],
            [
                ['kind' => AdjustmentKind::Discount, 'type' => AdjustmentType::Percentage, 'value' => 1_000],
                ['kind' => AdjustmentKind::Fee, 'type' => AdjustmentType::Fixed, 'value' => 50_000],
            ],
        );

        $this->assertSame(-400_000, $totals->adjustments[0]->minor);
        $this->assertSame(50_000, $totals->adjustments[1]->minor);
        $this->assertSame(3_650_000, $totals->total->minor);
        $this->assertSame(Currency::PKR, $totals->total->currency);
    }

    public function test_an_invoice_with_no_lines_totals_zero(): void
    {
        $totals = $this->calculator->calculate(Currency::USD, []);

        $this->assertSame(0, $totals->total->minor);
    }

    public function test_the_same_input_always_produces_the_same_cents(): void
    {
        $lines = [['quantity' => 16_850, 'unit_price' => 555], ['quantity' => 333, 'unit_price' => 1_999]];
        $adjustments = [['kind' => AdjustmentKind::Fee, 'type' => AdjustmentType::Percentage, 'value' => 333]];

        $first = $this->calculator->calculate(Currency::USD, $lines, $adjustments);
        $second = $this->calculator->calculate(Currency::USD, $lines, $adjustments);

        $this->assertTrue($first->total->equals($second->total));
    }

    public function test_a_negative_quantity_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(Currency::USD, [['quantity' => -100, 'unit_price' => 500]]);
    }

    public function test_a_negative_adjustment_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(
            Currency::USD,
            [['quantity' => 100, 'unit_price' => 500]],
            [['kind' => AdjustmentKind::Fee, 'type' => AdjustmentType::Fixed, 'value' => -1]],
        );
    }

    public function test_a_discount_larger_than_the_subtotal_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(
            Currency::USD,
            [['quantity' => 100, 'unit_price' => 500]],
            [['kind' => AdjustmentKind::Discount, 'type' => AdjustmentType::Fixed, 'value' => 501]],
        );
    }
}
