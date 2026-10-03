<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Database\Factories\InvoiceFactory;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A generated invoice: a snapshot that never reads the plan, client or team
 * again to describe itself. Addressed by public_id; `number` (INV-2026-0005)
 * is the business number, assigned once when it is issued.
 *
 * The model enforces the freeze whatever path a write takes:
 * - approved (scheduled): its amounts, lines and snapshot cannot change; only
 *   the approval fields can (cancel sending, or issuing);
 * - issued: nothing about it can ever change again.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_id
 * @property int|null $billing_plan_id
 * @property string|null $number
 * @property InvoiceStatus $status
 * @property int $version bumped on every change, so a stale review cannot approve
 * @property string $title
 * @property PricingMode $pricing_mode
 * @property DeliveryMode $delivery_mode
 * @property Currency $currency
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $generated_on
 * @property Carbon $planned_send_on
 * @property int $due_in_days
 * @property array{name: string, billing_email: string, cc_emails: list<string>, address: string|null, tax_id: string|null} $bill_to
 * @property int $subtotal_minor
 * @property int $adjustments_minor
 * @property int $total_minor
 * @property Carbon|null $issue_date
 * @property Carbon|null $due_date
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $send_after approved invoices wait until then; cancelling sending is possible before issue
 * @property Carbon|null $issued_at
 */
final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasPublicId;

    /** The only columns that may change while an invoice is approved and waiting to be issued. */
    private const APPROVAL_COLUMNS = ['status', 'version', 'approved_at', 'approved_by', 'send_after', 'issued_at', 'number', 'issue_date', 'due_date', 'updated_at'];

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'pricing_mode' => PricingMode::class,
            'delivery_mode' => DeliveryMode::class,
            'currency' => Currency::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'generated_on' => 'date',
            'planned_send_on' => 'date',
            'issue_date' => 'date',
            'due_date' => 'date',
            'approved_at' => 'datetime',
            'send_after' => 'datetime',
            'issued_at' => 'datetime',
            'bill_to' => 'array',
            'version' => 'integer',
            'due_in_days' => 'integer',
            'subtotal_minor' => 'integer',
            'adjustments_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $invoice) {
            $original = $invoice->getOriginal('status');

            if ($original === InvoiceStatus::Issued) {
                throw new LogicException('Issued invoices are immutable.');
            }

            if ($original === InvoiceStatus::Approved && array_diff(array_keys($invoice->getDirty()), self::APPROVAL_COLUMNS) !== []) {
                throw new LogicException('An approved invoice cannot change its amounts. Cancel sending first.');
            }
        });

        self::deleting(function (self $invoice) {
            if ($invoice->status->locksFinancials()) {
                throw new LogicException('Approved and issued invoices cannot be deleted.');
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InvoiceAdjustment::class)->orderBy('position');
    }

    public function isApproved(): bool
    {
        return $this->status === InvoiceStatus::Approved;
    }

    public function isIssued(): bool
    {
        return $this->status === InvoiceStatus::Issued;
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
