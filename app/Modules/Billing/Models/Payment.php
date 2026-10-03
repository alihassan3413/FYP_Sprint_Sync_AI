<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\PaymentMethod;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Money received against one issued invoice. A financial record: its amount,
 * date and invoice never change after it is recorded, and it is never
 * deleted. The only allowed change is voiding it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $invoice_id
 * @property string $idempotency_key
 * @property int $amount_minor
 * @property Currency $currency
 * @property Carbon $received_on
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $note
 * @property int|null $recorded_by
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 */
final class Payment extends Model
{
    use HasPublicId;

    private const VOID_COLUMNS = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'received_on' => 'date',
            'method' => PaymentMethod::class,
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $payment) {
            if ($payment->getOriginal('voided_at') !== null || array_diff(array_keys($payment->getDirty()), self::VOID_COLUMNS) !== []) {
                throw new LogicException('Payments cannot be changed; void it and record a new one.');
            }
        });

        self::deleting(fn () => throw new LogicException('Payments are never deleted; void it instead.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('voided_at');
    }
}
