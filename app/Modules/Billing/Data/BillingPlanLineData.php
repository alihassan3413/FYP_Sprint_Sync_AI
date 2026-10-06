<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A recurring invoice line for the browser. A team member is referenced by
 * their public id; the sequential person id never leaves the server.
 */
#[TypeScript]
final class BillingPlanLineData extends Data
{
    public function __construct(
        public ?string $person_public_id,
        public string $description,
        public ?string $role_label,
        public int $unit_price_minor,
    ) {}
}
