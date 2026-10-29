<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\InvoiceDisplayStatus;
use App\Modules\Billing\Data\InvoiceSource;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\PaymentStatus;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Database\Factories\InvoiceFactory;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property array<string, string|null>|null $bill_from sender snapshot (see SenderSnapshot); null on invoices prepared before invoicing details existed
 * @property list<int>|null $time_project_ids projects whose tracked time this invoice bills (hourly; null = hours entered by hand)
 * @property Carbon|null $tracked_at when recorded hours were last read from time tracking
 * @property string|null $pdf_path the official PDF, written once after issue
 * @property int $subtotal_minor
 * @property int $adjustments_minor
 * @property int $total_minor
 * @property Carbon|null $issue_date
 * @property Carbon|null $due_date
 * @property Carbon|null $approved_at
 * @property int|null $approved_by null when SprintSync approved it (approved_automatically)
 * @property bool $approved_automatically
 * @property Carbon|null $send_after approved invoices wait until then; cancelling sending is possible before issue
 * @property Carbon|null $issued_at
 * @property Carbon|null $automation_checked_at when automatic sending made its one decision
 * @property list<array{code: string, message: string}>|null $automation_hold why automatic sending held it for the owner
 */
final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasPublicId;

    /** The only columns that may change while an invoice is approved and waiting to be issued. */
    private const APPROVAL_COLUMNS = ['status', 'version', 'approved_at', 'approved_by', 'approved_automatically', 'send_after', 'issued_at', 'number', 'issue_date', 'due_date', 'updated_at'];

    protected $guarded = ['id', 'public_id'];

    protected $attributes = ['source' => 'plan'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'source' => InvoiceSource::class,
            'voided_at' => 'datetime',
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
            'approved_automatically' => 'boolean',
            'automation_checked_at' => 'datetime',
            'automation_hold' => 'array',
            'bill_to' => 'array',
            'bill_from' => 'array',
            'time_project_ids' => 'array',
            'tracked_at' => 'datetime',
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

    public function isManual(): bool
    {
        return $this->source === InvoiceSource::Manual;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function displayStatus(): InvoiceDisplayStatus
    {
        return match (true) {
            $this->isVoided() => InvoiceDisplayStatus::Void,
            ! $this->isIssued() => InvoiceDisplayStatus::Draft,
            $this->paymentStatus() === PaymentStatus::Paid => InvoiceDisplayStatus::Paid,
            default => InvoiceDisplayStatus::Sent,
        };
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(InvoiceDelivery::class)->where('channel', InvoiceDelivery::CHANNEL_EMAIL);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * What has been paid: the sum of active (not voided) payments. Uses a
     * loaded `paid_minor` (withSum) when present, otherwise asks the database.
     */
    public function paidMinor(): int
    {
        if (array_key_exists('paid_minor', $this->attributes)) {
            return (int) $this->attributes['paid_minor'];
        }

        return (int) $this->payments()->active()->sum('amount_minor');
    }

    public function balanceDueMinor(): int
    {
        return $this->total_minor - $this->paidMinor();
    }

    /** Only meaningful once issued; drafts are not payable yet. */
    public function paymentStatus(): ?PaymentStatus
    {
        return $this->isIssued() ? PaymentStatus::for($this->total_minor, $this->paidMinor()) : null;
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
