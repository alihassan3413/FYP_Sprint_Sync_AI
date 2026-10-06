<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Contracts\ExchangeRateProvider;
use App\Modules\Billing\Contracts\MailTransportFactory;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoicingProfile;
use App\Modules\Billing\Models\WorkspaceMailSettings;
use App\Modules\Billing\Policies\BillingPlanPolicy;
use App\Modules\Billing\Policies\ClientPolicy;
use App\Modules\Billing\Policies\InvoicePolicy;
use App\Modules\Billing\Policies\InvoicingProfilePolicy;
use App\Modules\Billing\Policies\WorkspaceMailSettingsPolicy;
use App\Modules\Billing\Support\ManualExchangeRates;
use App\Modules\Billing\Support\SmtpMailTransportFactory;
use App\Support\Modules\ModuleServiceProvider;

final class BillingServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Billing';

    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ExchangeRateProvider::class => ManualExchangeRates::class,
        MailTransportFactory::class => SmtpMailTransportFactory::class,
    ];

    protected array $policies = [
        Client::class => ClientPolicy::class,
        BillingPlan::class => BillingPlanPolicy::class,
        Invoice::class => InvoicePolicy::class,
        InvoicingProfile::class => InvoicingProfilePolicy::class,
        WorkspaceMailSettings::class => WorkspaceMailSettingsPolicy::class,
    ];
}
