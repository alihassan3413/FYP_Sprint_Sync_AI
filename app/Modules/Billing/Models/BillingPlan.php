<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Database\Factories\BillingPlanFactory;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring invoice for one client, shown to the owner as "Recurring
 * invoice". Configuration only: invoices generated from it will snapshot their
 * lines, rates and adjustments, so editing a plan only affects future invoices.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_id
 * @property string $name
 * @property Currency $currency
 * @property PricingMode $pricing_mode
 * @property int $generation_day the draft is created (hourly: always the 1st)
 * @property int $send_day the invoice is planned to be sent (>= generation_day)
 * @property BillingPeriod $billing_period
 * @property int $due_in_days
 * @property DeliveryMode $delivery_mode
 * @property int|null $reminder_days_before
 * @property Carbon|null $paused_at
 */
final class BillingPlan extends Model
{
    /** @use HasFactory<BillingPlanFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'name',
        'currency',
        'pricing_mode',
        'generation_day',
        'send_day',
        'billing_period',
        'due_in_days',
        'delivery_mode',
        'reminder_days_before',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'pricing_mode' => PricingMode::class,
            'billing_period' => BillingPeriod::class,
            'delivery_mode' => DeliveryMode::class,
            'generation_day' => 'integer',
            'send_day' => 'integer',
            'due_in_days' => 'integer',
            'reminder_days_before' => 'integer',
            'paused_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillingPlanLine::class)->orderBy('position');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BillingPlanAdjustment::class)->orderBy('position');
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    protected static function newFactory(): BillingPlanFactory
    {
        return BillingPlanFactory::new();
    }
}
