<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

/**
 * The currencies a client can be billed in. Amounts are always stored as
 * integers in the currency's minor unit (cents, paisa), never as floats, and
 * values in different currencies are never added together.
 */
enum Currency: string
{
    case USD = 'USD';
    case PKR = 'PKR';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case AED = 'AED';
    case CAD = 'CAD';
    case AUD = 'AUD';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $currency) => ['value' => $currency->value, 'label' => "{$currency->value} — {$currency->label()}"],
            self::cases(),
        );
    }

    /**
     * Digits after the decimal point. Every currency listed today uses two;
     * code converting user input must still ask rather than assume.
     */
    public function minorUnits(): int
    {
        return 2;
    }

    public function label(): string
    {
        return match ($this) {
            self::USD => 'US dollar',
            self::PKR => 'Pakistani rupee',
            self::EUR => 'Euro',
            self::GBP => 'British pound',
            self::AED => 'UAE dirham',
            self::CAD => 'Canadian dollar',
            self::AUD => 'Australian dollar',
        };
    }
}
