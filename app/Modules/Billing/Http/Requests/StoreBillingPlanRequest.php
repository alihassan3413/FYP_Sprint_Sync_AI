<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Models\BillingPlan;

final class StoreBillingPlanRequest extends BillingPlanRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [BillingPlan::class, $this->client()]) ?? false;
    }

    protected function currentPlan(): ?BillingPlan
    {
        return null;
    }
}
