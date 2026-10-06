<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * value is basis points for a percentage (2% = 200) or minor units for a
 * fixed amount.
 */
#[TypeScript]
final class BillingPlanAdjustmentData extends Data
{
    public function __construct(
        #[LiteralTypeScriptType("'fee' | 'tax' | 'discount'")]
        public string $kind,
        #[LiteralTypeScriptType("'percentage' | 'fixed'")]
        public string $type,
        public string $label,
        public int $value,
    ) {}
}
