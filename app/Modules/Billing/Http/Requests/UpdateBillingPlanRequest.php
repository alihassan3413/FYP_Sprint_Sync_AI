<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Models\BillingPlan;

final class UpdateBillingPlanRequest extends BillingPlanRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->plan()) ?? false;
    }

    public function plan(): BillingPlan
    {
        return $this->route('billingPlan');
    }

    protected function currentPlan(): ?BillingPlan
    {
        return $this->plan();
    }
}
