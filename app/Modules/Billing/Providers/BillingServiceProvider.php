<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoicingProfile;
use App\Modules\Billing\Policies\BillingPlanPolicy;
use App\Modules\Billing\Policies\ClientPolicy;
use App\Modules\Billing\Policies\InvoicePolicy;
use App\Modules\Billing\Policies\InvoicingProfilePolicy;
use App\Support\Modules\ModuleServiceProvider;

final class BillingServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Billing';

    protected array $policies = [
        Client::class => ClientPolicy::class,
        BillingPlan::class => BillingPlanPolicy::class,
        Invoice::class => InvoicePolicy::class,
        InvoicingProfile::class => InvoicingProfilePolicy::class,
    ];
}
