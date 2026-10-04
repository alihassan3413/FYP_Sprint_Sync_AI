<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Data\DeliveryStatus;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The single email delivery of an issued invoice, with its recipients, subject
 * and visible From snapshotted. Status only changes through conditional
 * updates in the delivery actions (see DeliveryStatus for the transitions).
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $invoice_id
 * @property string $channel
 * @property DeliveryStatus $status
 * @property string $recipient
 * @property list<string>|null $cc
 * @property string $subject
 * @property string $from_email
 * @property string $from_name
 * @property string|null $reply_to_email
 * @property string $message_id fixed per invoice, so a resend carries the same Message-ID
 * @property int $attempts
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $error sanitised reason, never credentials
 */
final class InvoiceDelivery extends Model
{
    use HasPublicId;

    public const CHANNEL_EMAIL = 'email';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'cc' => 'array',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
