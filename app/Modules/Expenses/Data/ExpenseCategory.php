<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Plain categories, not a chart of accounts. */
#[TypeScript]
enum ExpenseCategory: string
{
    case Payroll = 'payroll';
    case OfficeRent = 'office_rent';
    case Utilities = 'utilities';
    case Software = 'software';
    case Contractors = 'contractors';
    case Marketing = 'marketing';
    case Travel = 'travel';
    case Equipment = 'equipment';
    case ProfessionalServices = 'professional_services';
    case TaxesFees = 'taxes_fees';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Payroll => 'Payroll / Team',
            self::OfficeRent => 'Office rent',
            self::Utilities => 'Utilities',
            self::Software => 'Software',
            self::Contractors => 'Contractors',
            self::Marketing => 'Marketing',
            self::Travel => 'Travel',
            self::Equipment => 'Equipment',
            self::ProfessionalServices => 'Professional services',
            self::TaxesFees => 'Taxes / Fees',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category) => ['value' => $category->value, 'label' => $category->label()], self::cases());
    }
}
