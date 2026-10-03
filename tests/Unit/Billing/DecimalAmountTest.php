<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Modules\Billing\Support\DecimalAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalAmountTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function amounts(): array
    {
        return [
            'whole dollars' => ['2000', 200000],
            'two decimals' => ['5.55', 555],
            'one decimal' => ['4.5', 450],
            'trailing dot' => ['180.', 18000],
            'zero' => ['0', 0],
            'percentage 2%' => ['2', 200],
            'percentage 2.5%' => ['2.5', 250],
            'value floats get wrong' => ['0.29', 29],
            'surrounding spaces' => [' 1100.00 ', 110000],
        ];
    }

    #[DataProvider('amounts')]
    public function test_decimals_become_exact_integers(string $input, int $expected): void
    {
        $this->assertSame($expected, DecimalAmount::scaled($input, 2));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalid(): array
    {
        return [
            'three decimals' => ['5.555'],
            'negative' => ['-5'],
            'letters' => ['abc'],
            'empty' => [''],
            'exponent' => ['1e3'],
            'two dots' => ['1.2.3'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_input_is_rejected(string $input): void
    {
        $this->assertFalse(DecimalAmount::isValid($input, 2));

        $this->expectException(InvalidArgumentException::class);
        DecimalAmount::scaled($input, 2);
    }

    public function test_the_whole_digit_limit_is_enforced(): void
    {
        $this->assertTrue(DecimalAmount::isValid('999999999.99', 2, 9));
        $this->assertFalse(DecimalAmount::isValid('1000000000', 2, 9));
    }
}
